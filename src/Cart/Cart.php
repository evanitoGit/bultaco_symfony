<?php

namespace App\Cart;

use App\Entity\Client;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Panier : [id de la pièce => quantité].
 * Les quantités sont limitées au stock des pièces (stock NULL = non suivi, toujours disponible).
 * - visiteur : stocké en session ;
 * - client connecté : stocké en base (table client_panier), donc retrouvé sur tous ses appareils.
 * Les prix sont toujours relus en base et calculés en centimes.
 */
final class Cart
{
    /** En dessous de ce stock, la boutique affiche « Plus que N en stock » */
    public const STOCK_FAIBLE = 3;

    private const SESSION_KEY = 'panier';
    private const MAX_QUANTITE = 99;

    /** @var list<string> quantités corrigées pendant cette requête (stock en baisse) */
    private array $ajustements = [];

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Connection $connection,
        private readonly Security $security,
    ) {
    }

    /**
     * @return int quantité de la pièce dans le panier après l'ajout (limitée au stock)
     */
    public function add(int $pieceId, int $quantite = 1): int
    {
        $lignes = $this->raw();
        $lignes[$pieceId] = min($this->maximum($pieceId), ($lignes[$pieceId] ?? 0) + max(1, $quantite));
        if ($lignes[$pieceId] <= 0) {
            unset($lignes[$pieceId]);
        }
        $this->save($lignes);

        return $lignes[$pieceId] ?? 0;
    }

    /**
     * @return int quantité réellement enregistrée (limitée au stock)
     */
    public function setQuantite(int $pieceId, int $quantite): int
    {
        $lignes = $this->raw();
        if (!isset($lignes[$pieceId])) {
            return 0; // on ne modifie que les lignes déjà présentes (add() vérifie l'existence de la pièce)
        }
        $quantite = min($this->maximum($pieceId), $quantite);
        if ($quantite <= 0) {
            unset($lignes[$pieceId]);
        } else {
            $lignes[$pieceId] = $quantite;
        }
        $this->save($lignes);

        return max(0, $quantite);
    }

    public function remove(int $pieceId): void
    {
        $this->setQuantite($pieceId, 0);
    }

    public function clear(): void
    {
        if ($client = $this->client()) {
            $this->connection->executeStatement('DELETE FROM client_panier WHERE client_id = ?', [$client->getId()]);

            return;
        }
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
    }

    /**
     * À la connexion : les articles ajoutés en tant que visiteur rejoignent le panier du compte
     * (les quantités s'additionnent), puis le panier de session est vidé.
     */
    public function fusionnerDansCompte(Client $client): void
    {
        $session = $this->requestStack->getSession();
        $panier = $session->get(self::SESSION_KEY, []);
        $session->remove(self::SESSION_KEY);

        foreach ($panier as $pieceId => $quantite) {
            // INSERT ... SELECT : ignore les pièces supprimées entre-temps
            $this->connection->executeStatement(
                'INSERT INTO client_panier (client_id, piece_id, quantite)
                 SELECT ?, id, ? FROM pieces_detachees WHERE id = ?
                 ON DUPLICATE KEY UPDATE quantite = LEAST(?, quantite + VALUES(quantite))',
                [$client->getId(), min(self::MAX_QUANTITE, (int) $quantite), (int) $pieceId, self::MAX_QUANTITE]
            );
        }
    }

    /** Quantité d'une pièce déjà dans le panier. */
    public function quantite(int $pieceId): int
    {
        return $this->raw()[$pieceId] ?? 0;
    }

    /** Nombre total d'articles (pour la pastille du header). */
    public function count(): int
    {
        return array_sum($this->raw());
    }

    /**
     * Lignes détaillées, avec les prix actuels de la base.
     * Si le stock a baissé depuis l'ajout, la quantité est corrigée et le client prévenu.
     *
     * @return list<array{id:int, nom:string, image_path:?string, quantite:int, stock:?int, prix_unitaire:int, sous_total:int}>
     */
    public function lignes(): array
    {
        $panier = $this->raw();
        if ($panier === []) {
            return [];
        }

        $pieces = $this->connection->fetchAllAssociativeIndexed(
            'SELECT id, nom, prix, stock, image_path FROM pieces_detachees WHERE id IN (?)',
            [array_keys($panier)],
            [ArrayParameterType::INTEGER]
        );

        $lignes = [];
        foreach ($panier as $id => $quantite) {
            if (!isset($pieces[$id])) {
                // pièce supprimée depuis l'administration : on la retire du panier
                $this->remove($id);
                continue;
            }
            $stock = $pieces[$id]['stock'] === null ? null : (int) $pieces[$id]['stock'];
            if ($stock !== null && $quantite > $stock) {
                $quantite = $this->setQuantite($id, $stock);
                $this->signaler($quantite === 0
                    ? sprintf('« %s » n\'est plus disponible et a été retiré de votre panier.', $pieces[$id]['nom'])
                    : sprintf('Il ne reste que %d « %s » en stock : la quantité de votre panier a été ajustée.', $stock, $pieces[$id]['nom']));
                if ($quantite === 0) {
                    continue;
                }
            }
            $prixUnitaire = (int) round((float) $pieces[$id]['prix'] * 100);
            $lignes[] = [
                'id' => $id,
                'nom' => $pieces[$id]['nom'],
                'image_path' => $pieces[$id]['image_path'],
                'quantite' => $quantite,
                'stock' => $stock,
                'prix_unitaire' => $prixUnitaire,
                'sous_total' => $prixUnitaire * $quantite,
            ];
        }

        return $lignes;
    }

    /**
     * Quantités corrigées pendant cette requête (stock en baisse depuis l'ajout).
     *
     * @return list<string>
     */
    public function ajustements(): array
    {
        return $this->ajustements;
    }

    /**
     * Quantité maximale autorisée pour une pièce : son stock (s'il est suivi), 99 au plus.
     * 0 si la pièce n'existe plus ou est en rupture.
     */
    public function maximum(int $pieceId): int
    {
        $stock = $this->connection->fetchOne('SELECT IFNULL(stock, ?) FROM pieces_detachees WHERE id = ?', [self::MAX_QUANTITE, $pieceId]);

        return $stock === false ? 0 : min(self::MAX_QUANTITE, (int) $stock);
    }

    /** Total du panier en centimes. */
    public function total(): int
    {
        return array_sum(array_column($this->lignes(), 'sous_total'));
    }

    /** @return array<int, int> */
    private function raw(): array
    {
        if ($client = $this->client()) {
            return array_map('intval', $this->connection->fetchAllKeyValue(
                'SELECT piece_id, quantite FROM client_panier WHERE client_id = ? ORDER BY date_ajout, piece_id',
                [$client->getId()]
            ));
        }

        $request = $this->requestStack->getCurrentRequest();
        // Ne démarre pas de session pour un visiteur qui n'a jamais rien ajouté
        if (!$request || (!$request->hasPreviousSession() && !$request->getSession()->isStarted())) {
            return [];
        }

        return $request->getSession()->get(self::SESSION_KEY, []);
    }

    private function save(array $lignes): void
    {
        if ($client = $this->client()) {
            $this->connection->transactional(function (Connection $connection) use ($client, $lignes): void {
                // on garde la date d'ajout des pièces déjà présentes (ordre d'affichage)
                $connection->executeStatement(
                    'DELETE FROM client_panier WHERE client_id = ? AND piece_id NOT IN (?)',
                    [$client->getId(), array_keys($lignes) ?: [0]],
                    [1 => ArrayParameterType::INTEGER]
                );
                foreach ($lignes as $pieceId => $quantite) {
                    $connection->executeStatement(
                        'INSERT INTO client_panier (client_id, piece_id, quantite) VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE quantite = VALUES(quantite)',
                        [$client->getId(), $pieceId, $quantite]
                    );
                }
            });

            return;
        }

        $this->requestStack->getSession()->set(self::SESSION_KEY, $lignes);
    }

    private function signaler(string $message): void
    {
        $this->ajustements[] = $message;
        $this->requestStack->getSession()->getFlashBag()->add('panier', $message);
    }

    private function client(): ?Client
    {
        $user = $this->security->getUser();

        return $user instanceof Client && $user->getId() !== null ? $user : null;
    }
}
