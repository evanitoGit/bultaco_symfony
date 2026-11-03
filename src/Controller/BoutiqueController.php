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
        $ids = array_slice(array_unique(array_filter(array_map('intval', explode(',', $request->query->getString('ids'))))), 0, 12);
        $pieces = array_column($this->catalogue->pieces(), null, 'id');

        $response = $this->render('boutique/_pieces_vues.html.twig', [
            'pieces' => array_values(array_filter(array_map(fn (int $id) => $pieces[$id] ?? null, $ids))),
        ]);
        $response->headers->set('X-Robots-Tag', 'noindex');

        return $response;
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

        return $this->render('boutique/piece.html.twig', [
            'piece' => $piece,
            'similaires' => $this->catalogue->similaires($piece),
            'moto' => $this->motoChoisie($request, false),
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
