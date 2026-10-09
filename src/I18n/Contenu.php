<?php

namespace App\I18n;

use Doctrine\DBAL\Connection;
use Symfony\Component\Translation\LocaleSwitcher;

/**
 * Contenu saisi dans l'administration, traduit dans la langue de la page.
 *
 * Chaque champ traduisible a deux colonnes en plus : champ_en et champ_es.
 * Une traduction vide affiche le texte français.
 */
final class Contenu
{
    /** Langues du site (la première est celle d'origine) */
    public const LANGUES = ['fr' => 'Français', 'en' => 'English', 'es' => 'Español'];

    /** Langues qui ont des colonnes de traduction en base */
    public const TRADUCTIONS = ['en' => 'anglais', 'es' => 'espagnol'];

    public function __construct(
        private readonly LocaleSwitcher $localeSwitcher,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Langue en cours : celle de la page, ou celle d'un e-mail pendant son rendu.
     */
    public function langue(): string
    {
        $langue = $this->localeSwitcher->getLocale();

        return isset(self::LANGUES[$langue]) ? $langue : 'fr';
    }

    /**
     * Remplace chaque champ par sa traduction quand elle est renseignée.
     *
     * @param list<string> $champs ex. ['nom', 'description']
     */
    public function traduire(array $ligne, array $champs, ?string $langue = null): array
    {
        $langue ??= $this->langue();
        if (!isset(self::TRADUCTIONS[$langue])) {
            return $ligne;
        }

        foreach ($champs as $champ) {
            $traduction = $ligne[$champ . '_' . $langue] ?? null;
            if (is_string($traduction) && trim($traduction) !== '') {
                $ligne[$champ] = $traduction;
            }
        }

        return $ligne;
    }

    /**
     * @param list<array>  $lignes
     * @param list<string> $champs
     */
    public function traduireTout(array $lignes, array $champs, ?string $langue = null): array
    {
        return array_map(fn (array $ligne) => $this->traduire($ligne, $champs, $langue), $lignes);
    }

    /**
     * Texte de présentation d'une page (table textes), dans la langue de la page.
     */
    public function texte(string $section, string $defaut = ''): string
    {
        $ligne = $this->connection->fetchAssociative('SELECT contenu, contenu_en, contenu_es FROM textes WHERE section = ?', [$section]);

        return $ligne === false ? $defaut : (string) $this->traduire($ligne, ['contenu'])['contenu'];
    }
}
