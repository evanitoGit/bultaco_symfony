<?php

namespace App\Cart;

use App\Entity\Client;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Panier : [id de la pièce => quantité].
 * - visiteur : stocké en session ;
 * - client connecté : stocké en base (table client_panier), donc retrouvé sur tous ses appareils.
 * Les prix sont toujours relus en base et calculés en centimes.
 */
final class Cart
{
    private const SESSION_KEY = 'panier';
    private const MAX_QUANTITE = 99;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Connection $connection,
        private readonly Security $security,
    ) {
    }

    public function add(int $pieceId, int $quantite = 1): void
    {
        $lignes = $this->raw();
        $lignes[$pieceId] = min(self::MAX_QUANTITE, ($lignes[$pieceId] ?? 0) + max(1, $quantite));
        $this->save($lignes);
    }

    public function setQuantite(int $pieceId, int $quantite): void
    {
        $lignes = $this->raw();
        if (!isset($lignes[$pieceId])) {
            return; // on ne modifie que les lignes déjà présentes (add() vérifie l'existence de la pièce)
        }
        if ($quantite <= 0) {
            unset($lignes[$pieceId]);
        } else {
            $lignes[$pieceId] = min(self::MAX_QUANTITE, $quantite);
        }
        $this->save($lignes);
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

    /** Nombre total d'articles (pour la pastille du header). */
    public function count(): int
    {
        return array_sum($this->raw());
    }

    /**
     * Lignes détaillées, avec les prix actuels de la base.
     *
     * @return list<array{id:int, nom:string, image_path:?string, quantite:int, prix_unitaire:int, sous_total:int}>
     */
    public function lignes(): array
    {
        $panier = $this->raw();
        if ($panier === []) {
            return [];
        }

        $pieces = $this->connection->fetchAllAssociativeIndexed(
            'SELECT id, nom, prix, image_path FROM pieces_detachees WHERE id IN (?)',
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
            $prixUnitaire = (int) round((float) $pieces[$id]['prix'] * 100);
            $lignes[] = [
                'id' => $id,
                'nom' => $pieces[$id]['nom'],
                'image_path' => $pieces[$id]['image_path'],
                'quantite' => $quantite,
                'prix_unitaire' => $prixUnitaire,
                'sous_total' => $prixUnitaire * $quantite,
            ];
        }

        return $lignes;
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

    private function client(): ?Client
    {
        $user = $this->security->getUser();

        return $user instanceof Client && $user->getId() !== null ? $user : null;
    }
}
