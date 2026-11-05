<?php

namespace App\Recherche;

use App\Boutique\Catalogue;
use App\I18n\Contenu;
use App\Modele\PageModele;
use Doctrine\DBAL\Connection;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Recherche sur tout le site (loupe de l'en-tête, page /recherche) :
 * pièces, motos, modèles, pilotes, restaurations, pressbook et logos.
 *
 * Les contenus du club sont peu nombreux : ils sont lus puis filtrés en PHP, dans la langue
 * du visiteur, sans tenir compte des accents ni des majuscules (« piece » trouve « Pièce »).
 * Tous les mots tapés doivent être trouvés ; ceux du titre comptent plus que ceux de la description.
 */
final class RechercheGlobale
{
    /** Rubriques, dans l'ordre d'affichage */
    public const GROUPES = ['pieces', 'motos', 'modeles', 'pilotes', 'restaurations', 'documents'];

    public const LONGUEUR_MIN = 2;

    public function __construct(
        private readonly Connection $connection,
        private readonly Catalogue $catalogue,
        private readonly Contenu $contenu,
        private readonly UrlGeneratorInterface $urls,
        private readonly PageModele $pagesModeles,
    ) {
    }

    /**
     * @return array<string, array{total: int, resultats: list<array{titre: string, sous_titre: ?string, url: string, image: ?string, prix: ?string}>}>
     *         rubriques qui ont des résultats, avec au plus $parGroupe résultats chacune
     */
    public function rechercher(string $texte, int $parGroupe = 5): array
    {
        $mots = array_values(array_filter(explode(' ', self::normaliser($texte))));
        if (mb_strlen(implode('', $mots)) < self::LONGUEUR_MIN) {
            return [];
        }

        $groupes = [];
        foreach (self::GROUPES as $groupe) {
            $trouves = [];
            foreach ($this->elements($groupe) as $element) {
                $score = self::score($mots, $element['titre'], $element['texte']);
                if ($score > 0) {
                    $trouves[] = ['score' => $score] + $element;
                }
            }
            if ($trouves === []) {
                continue;
            }
            // les plus pertinents d'abord, puis par ordre alphabétique
            usort($trouves, fn (array $a, array $b) => [$b['score'], self::normaliser($a['titre'])] <=> [$a['score'], self::normaliser($b['titre'])]);
            $groupes[$groupe] = [
                'total' => count($trouves),
                'resultats' => array_map(fn (array $e) => array_diff_key($e, ['score' => 0, 'texte' => 0]), array_slice($trouves, 0, $parGroupe)),
            ];
        }

        return $groupes;
    }

    /** Minuscules, sans accents ni ponctuation superflue : « Pièce (Sherpa-T) » -> « piece sherpa-t » */
    public static function normaliser(string $texte): string
    {
        $texte = \Normalizer::normalize(mb_strtolower($texte), \Normalizer::FORM_D);
        $texte = preg_replace('/\p{Mn}+/u', '', (string) $texte);

        return trim((string) preg_replace('/[^\p{L}\p{N}.\-]+/u', ' ', $texte));
    }

    /** 0 : un mot manque ; sinon plus c'est haut, plus c'est pertinent */
    private static function score(array $mots, string $titre, string $texte): int
    {
        $titre = self::normaliser($titre);
        $tout = $titre . ' ' . self::normaliser($texte);
        $score = 1;
        foreach ($mots as $mot) {
            if (!str_contains($tout, $mot)) {
                return 0;
            }
            if (str_contains($titre, $mot)) {
                $score += 2;
            }
        }
        if (str_starts_with($titre, $mots[0])) {
            $score += 3;
        }

        return $score;
    }

    /**
     * @return iterable<array{titre: string, sous_titre: ?string, url: string, image: ?string, prix: ?string, texte: string}>
     */
    private function elements(string $groupe): iterable
    {
        switch ($groupe) {
            case 'pieces':
                foreach ($this->catalogue->pieces() as $p) {
                    yield [
                        'titre' => $p['nom'],
                        'sous_titre' => $p['categorie'] . ' › ' . $p['sous_categorie'],
                        'url' => $p['url'],
                        'image' => $p['image_path'] ?: null,
                        'prix' => $p['prix'],
                        // le nom français reste trouvable dans les autres langues
                        'texte' => implode(' ', [$p['nom_fr'] ?? '', $p['reference_origine'] ?? '', $p['description'] ?? '', $p['categorie'], $p['sous_categorie']]),
                    ];
                }
                break;

            case 'motos':
                foreach ($this->catalogue->modelesMoto() as $m) {
                    yield [
                        'titre' => $m['nom'],
                        'sous_titre' => trim(($m['cylindree'] ?? '') . ' ' . ($m['annees'] ?? '')) ?: null,
                        'url' => $this->urls->generate('app_boutique_pieces', ['moto' => $m['id']]),
                        'image' => null,
                        'prix' => null,
                        'texte' => implode(' ', [$m['cylindree'] ?? '', $m['annees'] ?? '']),
                    ];
                }
                break;

            case 'modeles':
                $modeles = $this->connection->fetchAllAssociative('SELECT * FROM modeles_emblematiques ORDER BY ordre ASC, id ASC');
                foreach ($this->contenu->traduireTout($modeles, ['type_moto', 'description']) as $m) {
                    $annees = ($m['annee_debut'] || $m['annee_fin']) ? ($m['annee_debut'] ?: '?') . ' – ' . ($m['annee_fin'] ?: '?') : null;
                    yield [
                        'titre' => $m['nom'],
                        'sous_titre' => implode(' · ', array_filter([$m['type_moto'], $annees])) ?: null,
                        'url' => $this->pagesModeles->url($m),
                        'image' => $m['image_path'] ?: null,
                        'prix' => null,
                        'texte' => implode(' ', [$m['type_moto'] ?? '', $m['cylindree'] ?? '', $m['annee_debut'] ?? '', $m['annee_fin'] ?? '', $m['description'] ?? '']),
                    ];
                }
                break;

            case 'pilotes':
                $pilotes = $this->connection->fetchAllAssociative('SELECT * FROM pilotes_emblematiques ORDER BY ordre ASC, id ASC');
                foreach ($this->contenu->traduireTout($pilotes, ['nationalite', 'description', 'palmares']) as $p) {
                    yield [
                        'titre' => trim(($p['prenom'] ?? '') . ' ' . $p['nom']),
                        'sous_titre' => $p['nationalite'] ?: null,
                        'url' => $this->urls->generate('app_modeles_pilotes') . '#pilote-' . $p['id'],
                        'image' => $p['image_path'] ?: null,
                        'prix' => null,
                        'texte' => implode(' ', [$p['nationalite'] ?? '', $p['palmares'] ?? '', $p['description'] ?? '']),
                    ];
                }
                break;

            case 'restaurations':
                $restaurations = $this->connection->fetchAllAssociative('SELECT * FROM restaurations WHERE photo_avant IS NOT NULL OR photo_apres IS NOT NULL ORDER BY ordre ASC, id ASC');
                foreach ($this->contenu->traduireTout($restaurations, ['titre']) as $r) {
                    yield [
                        'titre' => $r['titre'],
                        'sous_titre' => null,
                        'url' => $this->urls->generate('app_modeles_restaurations') . '#restauration-' . $r['id'],
                        'image' => $r['photo_apres'] ?: $r['photo_avant'],
                        'prix' => null,
                        'texte' => '',
                    ];
                }
                break;

            case 'documents':
                $documents = $this->connection->fetchAllAssociative('SELECT * FROM pressbook ORDER BY date_publication DESC, id DESC');
                foreach ($this->contenu->traduireTout($documents, ['titre', 'date_publication', 'description']) as $d) {
                    $logo = $d['type_contenu'] === 'logo';
                    yield [
                        'titre' => trim($d['titre']),
                        'sous_titre' => implode(' · ', array_filter([$d['source'] ?? null, $d['date_publication'] ?? null])) ?: null,
                        'url' => $this->urls->generate($logo ? 'app_doc_logos' : 'app_doc_pressbook') . ($logo ? '#logo-' : '#doc-') . $d['id'],
                        'image' => $d['image_path'] ?: null,
                        'prix' => null,
                        'texte' => implode(' ', [$d['source'] ?? '', $d['date_publication'] ?? '', $d['description'] ?? '']),
                    ];
                }
                break;
        }
    }
}
