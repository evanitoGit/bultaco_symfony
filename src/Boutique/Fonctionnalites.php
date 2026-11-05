<?php

namespace App\Boutique;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * « Fonctionnalités en test » : chaque nouveauté a un interrupteur à trois positions dans l'administration.
 *  - désactivée : personne ne la voit ;
 *  - admins     : visible seulement par les administrateurs connectés, pour l'essayer sur le vrai site ;
 *  - tous       : ouverte à tous les visiteurs.
 * Les positions sont enregistrées dans la table `reglages` (clés test_<nom>).
 */
final class Fonctionnalites
{
    public const DESACTIVEE = 'off';
    public const ADMINS = 'admin';
    public const TOUS = 'tous';

    public const MODES = [
        self::DESACTIVEE => 'Désactivée',
        self::ADMINS => 'Administrateurs seulement',
        self::TOUS => 'Tout le monde',
    ];

    /** Fonctionnalités réglables, dans l'ordre d'affichage de l'administration */
    public const LISTE = [
        'mon_garage' => [
            'titre' => 'Mon garage',
            'description' => 'Les clients enregistrent leurs motos dans leur compte. La boutique leur propose leurs motos en un clic, et ils reçoivent un e-mail quand une pièce compatible est ajoutée (seulement quand la fonctionnalité est ouverte à tous).',
        ],
        'page_modele' => [
            'titre' => 'Une page par modèle',
            'description' => 'Chaque modèle de l\'album a sa propre page : photo, caractéristiques, pièces compatibles en vente, restaurations, pilotes et articles du pressbook qui en parlent.',
        ],
    ];

    public function __construct(
        private readonly Reglages $reglages,
        private readonly Security $security,
    ) {
    }

    public function mode(string $nom): string
    {
        $this->verifier($nom);
        $mode = $this->reglages->get('test_' . $nom, self::DESACTIVEE);

        return isset(self::MODES[$mode]) ? $mode : self::DESACTIVEE;
    }

    /** La fonctionnalité est visible pour le visiteur actuel */
    public function active(string $nom): bool
    {
        return match ($this->mode($nom)) {
            self::TOUS => true,
            self::ADMINS => $this->security->isGranted('ROLE_ADMIN'),
            default => false,
        };
    }

    /** Ouverte à tous les visiteurs (e-mails, plan du site…) */
    public function publique(string $nom): bool
    {
        return $this->mode($nom) === self::TOUS;
    }

    /** Visible par l'administrateur actuel seulement parce qu'elle est en test : on lui affiche une étiquette */
    public function enTest(string $nom): bool
    {
        return $this->mode($nom) === self::ADMINS && $this->active($nom);
    }

    /** Page d'une fonctionnalité fermée au visiteur actuel : comme si elle n'existait pas */
    public function exiger(string $nom): void
    {
        if (!$this->active($nom)) {
            throw new NotFoundHttpException(sprintf('Fonctionnalité « %s » non activée.', $nom));
        }
    }

    public function changer(string $nom, string $mode): void
    {
        $this->verifier($nom);
        $this->reglages->set('test_' . $nom, isset(self::MODES[$mode]) ? $mode : self::DESACTIVEE);
    }

    private function verifier(string $nom): void
    {
        if (!isset(self::LISTE[$nom])) {
            throw new \InvalidArgumentException(sprintf('Fonctionnalité inconnue : « %s ».', $nom));
        }
    }
}
