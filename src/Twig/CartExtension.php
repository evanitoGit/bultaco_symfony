<?php

namespace App\Twig;

use App\Cart\Cart;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Twig\TwigFunction;

class CartExtension extends AbstractExtension
{
    public function __construct(private readonly Cart $cart)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('panier_nombre', [$this->cart, 'count']),
        ];
    }

    public function getFilters(): array
    {
        // 8990 -> « 89,90 € »
        return [
            new TwigFilter('prix', fn (int $centimes) => number_format($centimes / 100, 2, ',', ' ') . ' €'),
        ];
    }
}
