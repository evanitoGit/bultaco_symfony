<?php

namespace App\Commande;

/**
 * Une pièce du panier n'est plus disponible en quantité suffisante au moment de valider la commande
 * (un autre client l'a commandée entre-temps).
 */
final class StockInsuffisantException extends \RuntimeException
{
    public function __construct(public readonly string $piece, public readonly int $disponible)
    {
        parent::__construct(sprintf('Stock insuffisant pour « %s » (%d disponible(s)).', $piece, $disponible));
    }
}
