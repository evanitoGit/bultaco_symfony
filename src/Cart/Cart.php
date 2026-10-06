<?php

namespace App\Cart;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Panier stocké en session : [id de la pièce => quantité].
 * Les prix sont toujours relus en base et calculés en centimes.
 */
final class Cart
{
    private const SESSION_KEY = 'panier';
    private const MAX_QUANTITE = 99;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Connection $connection,
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
        $this->requestStack->getSession()->remove(self::SESSION_KEY);
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
        $request = $this->requestStack->getCurrentRequest();
        // Ne démarre pas de session pour un visiteur qui n'a jamais rien ajouté
        if (!$request || (!$request->hasPreviousSession() && !$request->getSession()->isStarted())) {
            return [];
        }

        return $request->getSession()->get(self::SESSION_KEY, []);
    }

    private function save(array $lignes): void
    {
        $this->requestStack->getSession()->set(self::SESSION_KEY, $lignes);
    }
}
