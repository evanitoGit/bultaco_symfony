<?php

namespace App\Controller;

use App\Cart\Cart;
use App\I18n\Contenu;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/panier')]
class CartController extends AbstractController
{
    public function __construct(
        private readonly Cart $cart,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route('', name: 'app_panier', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('panier/index.html.twig', [
            'lignes' => $this->cart->lignes(),
            'total' => $this->cart->total(),
        ]);
    }

    #[Route('/apercu', name: 'app_panier_apercu', methods: ['GET'])]
    public function apercu(): Response
    {
        // Fragment HTML chargé dans la popup du header
        return $this->render('panier/_apercu.html.twig', [
            'lignes' => $this->cart->lignes(),
            'total' => $this->cart->total(),
        ]);
    }

    #[Route('/ajouter/{id}', name: 'app_panier_ajouter', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function ajouter(int $id, Request $request, Connection $connection, Contenu $contenu): Response
    {
        if ($erreur = $this->jetonInvalide($request, 'app_boutique_pieces')) {
            return $erreur;
        }

        $piece = $connection->fetchAssociative('SELECT nom, nom_en, nom_es, stock FROM pieces_detachees WHERE id = ?', [$id]);
        if ($piece === false) {
            throw $this->createNotFoundException('Pièce introuvable.');
        }
        $piece = $contenu->traduire($piece, ['nom']);

        $avant = $this->cart->quantite($id);
        $apres = $this->cart->add($id, $request->getPayload()->getInt('quantite', 1));

        $message = match (true) {
            $apres > $avant => $this->translator->trans('panier.flash.ajoute', ['nom' => $piece['nom']]),
            $piece['stock'] !== null && (int) $piece['stock'] === 0 => $this->translator->trans('panier.flash.rupture', ['nom' => $piece['nom']]),
            default => $this->translator->trans('panier.flash.deja_tout_le_stock', ['nom' => $piece['nom'], 'stock' => $apres]),
        };

        return $this->repondre($request, $message, 'app_boutique_pieces', $apres > $avant);
    }

    /**
     * « Tout ajouter au panier » depuis Ma sélection : une de chaque pièce disponible,
     * sans augmenter la quantité de celles déjà dans le panier.
     */
    #[Route('/ajouter-selection', name: 'app_panier_ajouter_selection', methods: ['POST'])]
    public function ajouterSelection(Request $request, Connection $connection): Response
    {
        if ($erreur = $this->jetonInvalide($request, 'app_boutique_selection')) {
            return $erreur;
        }

        $ids = array_slice(array_unique(array_filter(array_map('intval', explode(',', $request->getPayload()->getString('ids'))))), 0, BoutiqueController::SELECTION_MAX);
        $ajoutees = 0;
        $indisponibles = 0;
        foreach ($ids as $id) {
            $stock = $connection->fetchOne('SELECT stock FROM pieces_detachees WHERE id = ?', [$id]);
            if ($stock === false || $this->cart->quantite($id) > 0) {
                continue; // pièce supprimée, ou déjà dans le panier
            }
            if ($stock !== null && (int) $stock <= 0) {
                ++$indisponibles;
                continue;
            }
            $this->cart->add($id);
            ++$ajoutees;
        }

        $message = $this->translator->trans('panier.flash.selection_ajoutee', ['n' => $ajoutees]);
        if ($indisponibles > 0) {
            $message .= ' ' . $this->translator->trans('panier.flash.selection_rupture', ['n' => $indisponibles]);
        }

        return $this->repondre($request, $message, 'app_panier', $ajoutees > 0);
    }

    #[Route('/quantite/{id}', name: 'app_panier_quantite', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function quantite(int $id, Request $request): Response
    {
        if ($erreur = $this->jetonInvalide($request, 'app_panier')) {
            return $erreur;
        }
        $demande = $request->getPayload()->getInt('quantite');
        $quantite = $this->cart->setQuantite($id, $demande);

        return $this->repondre($request, $demande > $quantite && $quantite > 0
            ? $this->translator->trans('panier.flash.quantite_limitee', ['stock' => $quantite])
            : $this->translator->trans('panier.flash.quantite_maj'), 'app_panier');
    }

    #[Route('/retirer/{id}', name: 'app_panier_retirer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retirer(int $id, Request $request): Response
    {
        if ($erreur = $this->jetonInvalide($request, 'app_panier')) {
            return $erreur;
        }
        $this->cart->remove($id);

        return $this->repondre($request, $this->translator->trans('panier.flash.retire'), 'app_panier');
    }

    #[Route('/vider', name: 'app_panier_vider', methods: ['POST'])]
    public function vider(Request $request): Response
    {
        if ($erreur = $this->jetonInvalide($request, 'app_panier')) {
            return $erreur;
        }
        $this->cart->clear();

        return $this->repondre($request, $this->translator->trans('panier.flash.vide'), 'app_panier');
    }

    /**
     * Jeton CSRF invalide (session expirée…) : message au visiteur plutôt qu'une erreur.
     */
    private function jetonInvalide(Request $request, string $route): ?Response
    {
        if ($this->isCsrfTokenValid('panier', $request->getPayload()->getString('_token'))) {
            return null;
        }

        $message = $this->translator->trans('commun.session_expiree');
        if ($request->isXmlHttpRequest()) {
            return $this->json(['message' => $message], Response::HTTP_BAD_REQUEST);
        }

        $this->addFlash('panier', $message);

        return $this->redirectToRoute($route);
    }

    /** JSON pour les appels JavaScript, sinon redirection classique. */
    private function repondre(Request $request, string $message, string $route, bool $ajoute = true): Response
    {
        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'message' => $message,
                'ajoute' => $ajoute,
                'nombre' => $this->cart->count(),
                'total' => $this->cart->total(),
            ]);
        }

        $this->addFlash('panier', $message);

        return $this->redirectToRoute($route);
    }
}
