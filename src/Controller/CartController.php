<?php

namespace App\Controller;

use App\Cart\Cart;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/panier')]
class CartController extends AbstractController
{
    public function __construct(private readonly Cart $cart)
    {
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
    public function ajouter(int $id, Request $request, Connection $connection): Response
    {
        if ($erreur = $this->jetonInvalide($request, 'app_boutique_pieces')) {
            return $erreur;
        }

        $piece = $connection->fetchAssociative('SELECT nom, stock FROM pieces_detachees WHERE id = ?', [$id]);
        if ($piece === false) {
            throw $this->createNotFoundException('Pièce introuvable.');
        }

        $avant = $this->cart->quantite($id);
        $apres = $this->cart->add($id, $request->getPayload()->getInt('quantite', 1));

        $message = match (true) {
            $apres > $avant => sprintf('« %s » a été ajouté au panier.', $piece['nom']),
            $piece['stock'] !== null && (int) $piece['stock'] === 0 => sprintf('« %s » est en rupture de stock.', $piece['nom']),
            default => sprintf('Il ne reste que %d « %s » en stock, déjà dans votre panier.', $apres, $piece['nom']),
        };

        return $this->repondre($request, $message, 'app_boutique_pieces', $apres > $avant);
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
            ? sprintf('Il ne reste que %d exemplaire%s en stock : la quantité a été ajustée.', $quantite, $quantite > 1 ? 's' : '')
            : 'Quantité mise à jour.', 'app_panier');
    }

    #[Route('/retirer/{id}', name: 'app_panier_retirer', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function retirer(int $id, Request $request): Response
    {
        if ($erreur = $this->jetonInvalide($request, 'app_panier')) {
            return $erreur;
        }
        $this->cart->remove($id);

        return $this->repondre($request, 'Article retiré du panier.', 'app_panier');
    }

    #[Route('/vider', name: 'app_panier_vider', methods: ['POST'])]
    public function vider(Request $request): Response
    {
        if ($erreur = $this->jetonInvalide($request, 'app_panier')) {
            return $erreur;
        }
        $this->cart->clear();

        return $this->repondre($request, 'Le panier a été vidé.', 'app_panier');
    }

    /**
     * Jeton CSRF invalide (session expirée…) : message au visiteur plutôt qu'une erreur.
     */
    private function jetonInvalide(Request $request, string $route): ?Response
    {
        if ($this->isCsrfTokenValid('panier', $request->getPayload()->getString('_token'))) {
            return null;
        }

        $message = 'Votre session a expiré, veuillez réessayer.';
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
