<?php

namespace App\Boutique;

use App\I18n\Contenu;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Pièces de la boutique : liste, page d'une pièce, compatibilité avec les modèles de motos.
 * Les noms et descriptions sont renvoyés dans la langue de la page.
 */
final class Catalogue
{
    private const SELECT_PIECES = '
        SELECT p.*,
               sc.nom AS sous_categorie, sc.nom_en AS sous_categorie_en, sc.nom_es AS sous_categorie_es,
               c.id AS categorie_id, c.nom AS categorie, c.nom_en AS categorie_en, c.nom_es AS categorie_es
        FROM pieces_detachees p
        INNER JOIN sous_categories sc ON p.sous_categorie_id = sc.id
        INNER JOIN categories c ON sc.categorie_id = c.id';

    private const CHAMPS_TRADUITS = ['nom', 'description', 'description_longue', 'sous_categorie', 'categorie'];

    public function __construct(
        private readonly Connection $connection,
        private readonly Contenu $contenu,
        private readonly UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * Toutes les pièces, triées par nom dans la langue de la page, avec l'adresse de leur page.
     *
     * @param int|null $modeleId ne garder que les pièces compatibles avec ce modèle de moto (et les universelles)
     */
    public function pieces(?int $modeleId = null): array
    {
        $pieces = $this->connection->fetchAllAssociative(self::SELECT_PIECES);
        $compatibles = $modeleId ? array_flip($this->idsCompatibles($modeleId)) : [];

        $resultat = [];
        foreach ($pieces as $piece) {
            if ($modeleId && !$piece['universelle'] && !isset($compatibles[$piece['id']])) {
                continue;
            }
            $piece = $this->traduire($piece);
            $piece['url'] = $this->url($piece);
            $piece['compatible'] = isset($compatibles[$piece['id']]);
            $resultat[] = $piece;
        }

        // tri alphabétique dans la langue de la page (les noms traduits n'ont pas le même ordre)
        $collator = new \Collator($this->contenu->langue());
        usort($resultat, fn (array $a, array $b) => $collator->compare($a['nom'], $b['nom']));

        return $resultat;
    }

    /**
     * Une pièce avec tout ce qu'affiche sa page : photos, modèles compatibles.
     */
    public function piece(int $id): ?array
    {
        $piece = $this->connection->fetchAssociative(self::SELECT_PIECES . ' WHERE p.id = ?', [$id]);
        if ($piece === false) {
            return null;
        }

        $piece = $this->traduire($piece);
        $piece['url'] = $this->url($piece);
        $piece['photos'] = array_values(array_filter(array_merge(
            [$piece['image_path']],
            $this->connection->fetchFirstColumn('SELECT image_path FROM piece_photos WHERE piece_id = ? ORDER BY ordre ASC, id ASC', [$id])
        )));
        $piece['modeles'] = $this->connection->fetchAllAssociative(
            'SELECT m.* FROM modeles_moto m INNER JOIN piece_modeles pm ON pm.modele_id = m.id WHERE pm.piece_id = ? ORDER BY m.ordre ASC, m.nom ASC',
            [$id]
        );

        return $piece;
    }

    /**
     * Pièce dans une langue donnée (ex. e-mail au visiteur, envoyé depuis l'administration en français).
     */
    public function pieceTraduite(array $piece, string $langue): array
    {
        $brut = $this->connection->fetchAssociative(self::SELECT_PIECES . ' WHERE p.id = ?', [$piece['id']]);

        return $brut === false ? $piece : ['nom_fr' => $brut['nom']] + $this->contenu->traduire($brut, self::CHAMPS_TRADUITS, $langue);
    }

    /**
     * Quelques autres pièces de la même sous-catégorie (sinon de la même catégorie).
     */
    public function similaires(array $piece, int $nombre = 4): array
    {
        $ids = $this->connection->fetchFirstColumn(
            'SELECT p.id FROM pieces_detachees p INNER JOIN sous_categories sc ON sc.id = p.sous_categorie_id
             WHERE p.id <> ? AND sc.categorie_id = ?
             ORDER BY (p.sous_categorie_id = ?) DESC, (p.stock IS NULL OR p.stock > 0) DESC, p.date_ajout DESC
             LIMIT ' . $nombre,
            [$piece['id'], $piece['categorie_id'], $piece['sous_categorie_id']]
        );
        if ($ids === []) {
            return [];
        }

        $pieces = $this->connection->fetchAllAssociative(self::SELECT_PIECES . ' WHERE p.id IN (?)', [$ids], [ArrayParameterType::INTEGER]);
        $pieces = array_map(fn (array $p) => ['url' => $this->url($p)] + $this->traduire($p), $pieces);
        // même ordre que la requête des identifiants
        usort($pieces, fn (array $a, array $b) => array_search($a['id'], $ids) <=> array_search($b['id'], $ids));

        return $pieces;
    }

    /** Modèles de motos du menu « Ma moto » */
    public function modelesMoto(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM modeles_moto ORDER BY ordre ASC, nom ASC');
    }

    public function modeleMoto(int $id): ?array
    {
        return $this->connection->fetchAssociative('SELECT * FROM modeles_moto WHERE id = ?', [$id]) ?: null;
    }

    /**
     * Modèles de motos reliés à une fiche de l'album, indexés par l'identifiant de la fiche.
     *
     * @return array<int, list<array>>
     */
    public function modelesParFicheAlbum(): array
    {
        $parFiche = [];
        foreach ($this->connection->fetchAllAssociative('SELECT * FROM modeles_moto WHERE modele_album_id IS NOT NULL ORDER BY ordre ASC, nom ASC') as $modele) {
            $parFiche[(int) $modele['modele_album_id']][] = $modele;
        }

        return $parFiche;
    }

    /**
     * Adresse de la page d'une pièce : /boutique/pieces/12-piston-50mm-sherpa-t (le nom suit la langue).
     *
     * @param string|null $langue null = langue de la page
     */
    public function url(array $piece, ?string $langue = null, bool $absolue = false): string
    {
        $langue ??= $this->contenu->langue();
        // nom d'origine (français) si la pièce a déjà été traduite dans la langue de la page
        $nom = $this->contenu->traduire(['nom' => $piece['nom_fr'] ?? $piece['nom']] + $piece, ['nom'], $langue)['nom'];

        return $this->urlGenerator->generate(
            'app_boutique_piece',
            ['id' => $piece['id'], 'slug' => self::slug($nom), '_locale' => $langue],
            $absolue ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH
        );
    }

    public static function slug(string $texte): string
    {
        return (string) (new AsciiSlugger())->slug($texte)->lower()->truncate(80, '', false)->trim('-');
    }

    /** Traduction dans la langue de la page, en gardant le nom français (adresses dans les autres langues). */
    private function traduire(array $piece): array
    {
        return ['nom_fr' => $piece['nom']] + $this->contenu->traduire($piece, self::CHAMPS_TRADUITS);
    }

    /** @return list<int> pièces cochées comme compatibles avec ce modèle */
    private function idsCompatibles(int $modeleId): array
    {
        return array_map('intval', $this->connection->fetchFirstColumn('SELECT piece_id FROM piece_modeles WHERE modele_id = ?', [$modeleId]));
    }
}
