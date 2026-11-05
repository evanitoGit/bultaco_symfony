<?php

namespace App\Controller;

use App\Boutique\AlerteStock;
use App\Boutique\Catalogue;
use App\Entity\Client;
use App\I18n\Contenu;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

class BoutiqueController extends AbstractController
{
    /** Nombre maximal de pièces dans « Ma sélection » (même limite dans script.js) */
    public const SELECTION_MAX = 50;

    /** Modèle choisi dans « Ma moto », gardé pour les visites suivantes */
    private const SESSION_MOTO = 'boutique_moto';

    public function __construct(
        private readonly Catalogue $catalogue,
        private readonly Contenu $contenu,
    ) {
    }

    #[Route('/boutique/pieces', name: 'app_boutique_pieces')]
    public function pieces(Request $request, Connection $connection, TranslatorInterface $translator): Response
    {
        $moto = $this->motoChoisie($request);
        $pieces = $this->catalogue->pieces($moto['id'] ?? null);

        $categories = $this->contenu->traduireTout($connection
            ->executeQuery('SELECT * FROM categories ORDER BY ordre ASC')
            ->fetchAllAssociative(), ['nom']);

        $sousCategories = $this->contenu->traduireTout($connection
            ->executeQuery('SELECT * FROM sous_categories ORDER BY ordre ASC')
            ->fetchAllAssociative(), ['nom']);

        // Construction de l'arborescence catégorie > sous-catégorie > pièces
        $piecesParSousCategorie = [];
        foreach ($pieces as $piece) {
            $piecesParSousCategorie[$piece['sous_categorie_id']][] = $piece;
        }

        $sousCategoriesParCategorie = [];
        foreach ($sousCategories as $sousCategorie) {
            $sousCategorie['pieces'] = $piecesParSousCategorie[$sousCategorie['id']] ?? [];
            // filtre « Ma moto » : seules les sous-catégories qui ont des pièces compatibles
            if ($moto && $sousCategorie['pieces'] === []) {
                continue;
            }
            $sousCategoriesParCategorie[$sousCategorie['categorie_id']][] = $sousCategorie;
        }

        foreach ($categories as $i => &$categorie) {
            $categorie['sous_categories'] = $sousCategoriesParCategorie[$categorie['id']] ?? [];
            $categorie['nb_pieces'] = array_sum(array_map(
                fn (array $sousCategorie) => count($sousCategorie['pieces']),
                $categorie['sous_categories']
            ));
            if ($moto && $categorie['nb_pieces'] === 0) {
                unset($categories[$i]);
            }
        }
        unset($categorie);

        return $this->render('boutique/pieces.html.twig', [
            'texte_presentation' => $this->contenu->texte('pieces_detachees', $translator->trans('boutique.texte_defaut')),
            'categories' => array_values($categories),
            'pieces' => $pieces,
            'modeles_moto' => $this->catalogue->modelesMoto(),
            'moto' => $moto,
        ]);
    }

    /**
     * Page d'une pièce : /boutique/pieces/12-piston-50mm-sherpa-t
     * Seul le numéro compte : si le nom a changé, l'ancienne adresse redirige vers la nouvelle.
     */
    /**
     * « Vous avez consulté récemment » : petites cartes des pièces demandées (?ids=3,12,7),
     * dans cet ordre. La liste vient du navigateur du visiteur ; les pièces supprimées sont ignorées.
     */
    #[Route('/boutique/pieces/vues', name: 'app_boutique_pieces_vues', methods: ['GET'])]
    public function piecesVues(Request $request): Response
    {
        $response = $this->render('boutique/_pieces_vues.html.twig', [
            'pieces' => $this->piecesDemandees($request, 12),
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    /**
     * « Ma sélection » : les pièces gardées de côté avec le cœur. La liste est mémorisée dans
     * le navigateur du visiteur (sans compte) ; la page la charge ensuite avec l'adresse ci-dessous.
     */
    #[Route('/boutique/selection', name: 'app_boutique_selection', methods: ['GET'])]
    public function selection(): Response
    {
        return $this->render('boutique/selection.html.twig');
    }

    /** Cartes des pièces de la sélection (?ids=3,12,7), dans cet ordre ; les pièces supprimées sont ignorées */
    #[Route('/boutique/selection/liste', name: 'app_boutique_selection_liste', methods: ['GET'])]
    public function selectionListe(Request $request): Response
    {
        $response = $this->render('boutique/_selection_liste.html.twig', [
            'pieces' => $this->piecesDemandees($request, self::SELECTION_MAX),
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
    }

    /** Pièces dont les numéros sont passés dans ?ids=, dans l'ordre demandé */
    private function piecesDemandees(Request $request, int $max): array
    {
        $ids = array_slice(array_unique(array_filter(array_map('intval', explode(',', $request->query->getString('ids'))))), 0, $max);
        $pieces = array_column($this->catalogue->pieces(), null, 'id');

        return array_values(array_filter(array_map(fn (int $id) => $pieces[$id] ?? null, $ids)));
    }

    #[Route('/boutique/pieces/{id}-{slug}', name: 'app_boutique_piece', requirements: ['id' => '\d+', 'slug' => '[a-z0-9-]*'], methods: ['GET'])]
    public function piece(int $id, string $slug, Request $request, AlerteStock $alertes): Response
    {
        $piece = $this->catalogue->piece($id);
        if ($piece === null) {
            throw $this->createNotFoundException('Pièce introuvable.');
        }

        if ($request->getBaseUrl() . $request->getPathInfo() !== $piece['url']) {
            $query = $request->getQueryString();

            return $this->redirect($piece['url'] . ($query ? '?' . $query : ''), Response::HTTP_MOVED_PERMANENTLY);
        }

        // drapeaux et balises hreflang : adresse exacte de la pièce dans chaque langue
        $request->attributes->set('_urls_langues', array_combine(
            array_keys(Contenu::LANGUES),
            array_map(fn (string $langue) => $this->catalogue->url($piece, $langue), array_keys(Contenu::LANGUES))
        ));

        $moto = $this->motoChoisie($request, false);

        return $this->render('boutique/piece.html.twig', [
            'piece' => $piece,
            'similaires' => $this->catalogue->similaires($piece),
            'voisines' => $this->catalogue->voisines($piece, $moto['id'] ?? null),
            'moto' => $moto,
            // client connecté déjà inscrit à l'alerte de retour en stock
            'alerte_en_attente' => $this->getUser() instanceof Client && !AlerteStock::disponible($piece)
                && $alertes->enAttente($piece['id'], $this->getUser()->getEmail()),
        ]);
    }

    /**
     * Modèle choisi dans le menu « Ma moto » : ?moto=12 (0 = toutes les motos), sinon le dernier choix.
     */
    private function motoChoisie(Request $request, bool $modifiable = true): ?array
    {
        $session = $request->hasPreviousSession() || $request->getSession()->isStarted() ? $request->getSession() : null;

        if ($modifiable && $request->query->has('moto')) {
            $moto = $request->query->getInt('moto') > 0 ? $this->catalogue->modeleMoto($request->query->getInt('moto')) : null;
            if ($moto) {
                $request->getSession()->set(self::SESSION_MOTO, (int) $moto['id']);
            } else {
                $session?->remove(self::SESSION_MOTO);
            }

            return $moto;
        }

        $id = $session?->get(self::SESSION_MOTO);

        return $id ? $this->catalogue->modeleMoto($id) : null;
    }
}
