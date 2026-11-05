<?php

namespace App\Boutique;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * « Fonctionnalités en test » : chaque nouveauté a un interrupteur à trois positions dans l'administration.
 *  - désactivée : personne ne la voit ;
 *  - admins     : visible seulement par les administrateurs connectés et par les comptes clients
 *                 « testeurs » (e-mails listés dans l'administration), pour l'essayer sur le vrai site ;
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
        self::ADMINS => 'Administrateurs et testeurs',
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
            self::ADMINS => $this->security->isGranted('ROLE_ADMIN') || $this->testeur(),
            default => false,
        };
    }

    /**
     * Comptes clients autorisés à voir les fonctionnalités en test (un administrateur n'a pas d'espace
     * client : pour essayer « Mon garage », on se connecte avec un compte client testeur).
     *
     * @return list<string> adresses e-mail, en minuscules
     */
    public function testeurs(): array
    {
        $liste = preg_split('/[\s,;]+/', mb_strtolower((string) $this->reglages->get('test_comptes', '')), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_unique($liste));
    }

    public function changerTesteurs(string $texte): void
    {
        $emails = array_filter(
            preg_split('/[\s,;]+/', mb_strtolower($texte), -1, PREG_SPLIT_NO_EMPTY),
            fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL) !== false
        );
        $this->reglages->set('test_comptes', implode("\n", array_unique($emails)));
    }

    private function testeur(): bool
    {
        $utilisateur = $this->security->getUser();

        return $utilisateur !== null && $this->security->isGranted('ROLE_CLIENT')
            && in_array(mb_strtolower($utilisateur->getUserIdentifier()), $this->testeurs(), true);
    }

    /** Ouverte à tous les visiteurs (e-mails, plan du site…) */
    public function publique(string $nom): bool
    {
        return $this->mode($nom) === self::TOUS;
    }

    /** Visible par le visiteur actuel seulement parce qu'elle est en test : on lui affiche une étiquette */
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
