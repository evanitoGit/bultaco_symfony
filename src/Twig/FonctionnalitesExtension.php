<?php

namespace App\Twig;

use App\Boutique\Fonctionnalites;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Fonctionnalités en test dans les templates : {% if fonctionnalite('mon_garage') %} … {% endif %}
 */
class FonctionnalitesExtension extends AbstractExtension
{
    public function __construct(private readonly Fonctionnalites $fonctionnalites)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('fonctionnalite', $this->fonctionnalites->active(...)),
            new TwigFunction('fonctionnalite_en_test', $this->fonctionnalites->enTest(...)),
            new TwigFunction('fonctionnalite_mode', $this->fonctionnalites->mode(...)),
        ];
    }
}
