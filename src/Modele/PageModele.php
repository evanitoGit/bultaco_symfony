<?php

namespace App\Modele;

use App\Boutique\Catalogue;
use App\I18n\Contenu;
use App\Recherche\RechercheGlobale;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * « Une page par modèle » : tout ce que le site sait sur un modèle de l'album.
 *
 * Rien à relier à la main dans l'administration : les restaurations, pilotes et articles du pressbook
 * sont retrouvés par le nom du modèle (« Sherpa T ») ou d'une moto qui lui est rattachée
 * (« Sherpa T 350 ») dans leur titre ou leur texte. Les pièces sont celles compatibles avec ces motos.
 */
final class PageModele
{
    /** Pièces affichées sur la page (les autres : lien vers la boutique filtrée) */
    public const PIECES_MAX = 8;

    public function __construct(
        private readonly Connection $connection,
        private readonly Catalogue $catalogue,
        private readonly Contenu $contenu,
        private readonly UrlGeneratorInterface $urls,
    ) {
    }

    public function url(array $modele, ?string $langue = null, bool $absolue = false): string
    {
        return $this->urls->generate(
            'app_modele',
            ['id' => $modele['id'], 'slug' => Catalogue::slug($modele['nom']), '_locale' => $langue ?? $this->contenu->langue()],
            $absolue ? UrlGeneratorInterface::ABSOLUTE_URL : UrlGeneratorInterface::ABSOLUTE_PATH
        );
    }

    /** Tous les modèles de l'album, dans l'ordre d'affichage */
    public function modeles(): array
    {
        return $this->connection->fetchAllAssociative('SELECT * FROM modeles_emblematiques ORDER BY ordre ASC, id ASC');
    }

    public function fiche(int $id): ?array
    {
        $modeles = $this->modeles();
        $position = array_search($id, array_map('intval', array_column($modeles, 'id')), true);
        if ($position === false) {
            return null;
        }

        $modele = $this->contenu->traduire($modeles[$position], ['type_moto', 'description', 'caracteristiques']);
        $modele['url'] = $this->url($modele);
        $modele['caracteristiques_liste'] = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $modele['caracteristiques']))));

        // motos du menu « Ma moto » rattachées à cette fiche (Sherpa T 250, Sherpa T 350…)
        $modele['motos'] = $this->connection->fetchAllAssociative(
            'SELECT * FROM modeles_moto WHERE modele_album_id = ? ORDER BY ordre ASC, nom ASC',
            [$id]
        );

        // noms recherchés dans les autres contenus
        $noms = array_unique(array_filter(array_map(
            fn (string $nom) => trim(self::mots($nom)),
            [$modele['nom'], ...array_column($modele['motos'], 'nom')]
        )));

        $modele['pieces'] = $this->pieces(array_map('intval', array_column($modele['motos'], 'id')));
        $modele['restaurations'] = $this->contenu->traduireTout(array_values(array_filter(
            $this->connection->fetchAllAssociative('SELECT * FROM restaurations WHERE photo_avant IS NOT NULL OR photo_apres IS NOT NULL ORDER BY ordre ASC, id ASC'),
            fn (array $r) => self::mentionne($noms, $r['titre'], $r['titre_en'], $r['titre_es'])
        )), ['titre']);
        $modele['pilotes'] = $this->contenu->traduireTout(array_values(array_filter(
            $this->connection->fetchAllAssociative('SELECT * FROM pilotes_emblematiques ORDER BY ordre ASC, id ASC'),
            fn (array $p) => self::mentionne($noms, $p['description'], $p['palmares'], $p['description_en'], $p['palmares_en'], $p['description_es'], $p['palmares_es'])
        )), ['nationalite']);
        $modele['articles'] = $this->contenu->traduireTout(array_values(array_filter(
            $this->connection->fetchAllAssociative("SELECT * FROM pressbook WHERE type_contenu <> 'logo' ORDER BY id DESC"),
            fn (array $a) => self::mentionne($noms, $a['titre'], $a['description'], $a['titre_en'], $a['description_en'], $a['titre_es'], $a['description_es'])
        )), ['titre', 'date_publication', 'description']);

        // modèles voisins dans l'album (précédent / suivant)
        $modele['precedent'] = $position > 0 ? $modeles[$position - 1] + ['url' => $this->url($modeles[$position - 1])] : null;
        $modele['suivant'] = $position < count($modeles) - 1 ? $modeles[$position + 1] + ['url' => $this->url($modeles[$position + 1])] : null;

        return $modele;
    }

    /**
     * Pièces compatibles avec au moins une des motos (les pièces « universelles » ne sont pas listées :
     * elles iraient sur toutes les pages).
     *
     * @return array{liste: list<array>, total: int}
     */
    private function pieces(array $motos): array
    {
        if ($motos === []) {
            return ['liste' => [], 'total' => 0];
        }
        $ids = array_flip(array_map('intval', $this->connection->fetchFirstColumn(
            'SELECT DISTINCT piece_id FROM piece_modeles WHERE modele_id IN (?)',
            [$motos],
            [ArrayParameterType::INTEGER]
        )));
        $pieces = array_values(array_filter($this->catalogue->pieces(), fn (array $p) => isset($ids[(int) $p['id']])));

        return ['liste' => array_slice($pieces, 0, self::PIECES_MAX), 'total' => count($pieces)];
    }

    /** Un des noms apparaît comme mots entiers dans un des textes (sans tenir compte des accents ni de la casse) */
    private static function mentionne(array $noms, ?string ...$textes): bool
    {
        $texte = self::mots(implode(' ', array_filter($textes)));
        foreach ($noms as $nom) {
            if (str_contains($texte, ' ' . $nom . ' ')) {
                return true;
            }
        }

        return false;
    }

    /** « Sherpa-T 350. » -> « sherpa t 350 », entouré d'espaces pour chercher des mots entiers */
    private static function mots(string $texte): string
    {
        return ' ' . trim((string) preg_replace('/[\s.\-]+/u', ' ', RechercheGlobale::normaliser($texte))) . ' ';
    }
}
