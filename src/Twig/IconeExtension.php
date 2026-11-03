<?php

namespace App\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Icônes dessinées en SVG : {{ icone('fleche-gauche') }}
 *
 * Contrairement aux émojis et aux symboles (☰ ▶ ↗…), elles s'affichent pareil sur tous
 * les appareils et toutes les polices. Elles prennent la taille (1em) et la couleur du texte.
 */
class IconeExtension extends AbstractExtension
{
    /** Tracés 24×24 (traits arrondis, sans remplissage) */
    private const ICONES = [
        'menu' => '<path d="M4 6h16M4 12h16M4 18h16"/>',
        'chevron-droite' => '<path d="m9 6 6 6-6 6"/>',
        'chevron-gauche' => '<path d="m15 6-6 6 6 6"/>',
        'fleche-gauche' => '<path d="M19 12H5M11 6l-6 6 6 6"/>',
        'fleche-droite' => '<path d="M5 12h14M13 6l6 6-6 6"/>',
        'externe' => '<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>',
        'deconnexion' => '<path d="M9 20H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h3M16 16l4-4-4-4M20 12H10"/>',
        'agrandir' => '<path d="M15 3h6v6M9 21H3v-6M21 3l-7 7M3 21l7-7"/>',
        'fleche-haut' => '<path d="M12 19V5M6 11l6-6 6 6"/>',
        'itineraire' => '<path d="m3 11 19-9-9 19-2-8-8-2z"/>',
        'copier' => '<rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/>',
        'coche' => '<path d="M20 6 9 17l-5-5"/>',
    ];

    public function getFunctions(): array
    {
        return [
            new TwigFunction('icone', $this->icone(...), ['is_safe' => ['html']]),
        ];
    }

    public function icone(string $nom, string $classe = ''): string
    {
        if (!isset(self::ICONES[$nom])) {
            throw new \InvalidArgumentException(sprintf('Icône inconnue : "%s".', $nom));
        }

        return sprintf(
            '<svg class="icone icone-%s%s" width="1em" height="1em" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">%s</svg>',
            $nom,
            $classe !== '' ? ' ' . $classe : '',
            self::ICONES[$nom]
        );
    }
}
