<?php

namespace App\Twig;

use App\Cart\Cart;
use Twig\Extension\AbstractExtension;
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
}
