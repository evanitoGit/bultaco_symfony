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

        $nom = $connection->fetchOne('SELECT nom FROM pieces_detachees WHERE id = ?', [$id]);
        if ($nom === false) {
            throw $this->createNotFoundException('Pièce introuvable.');
        }

        $this->cart->add($id, $request->getPayload()->getInt('quantite', 1));

        return $this->repondre($request, sprintf('« %s » a été ajouté au panier.', $nom), 'app_boutique_pieces');
    }

    #[Route('/quantite/{id}', name: 'app_panier_quantite', requirements: ['id' => '\d+'], methods: ['POST'])]
    public function quantite(int $id, Request $request): Response
    {
        if ($erreur = $this->jetonInvalide($request, 'app_panier')) {
            return $erreur;
        }
        $this->cart->setQuantite($id, $request->getPayload()->getInt('quantite'));

        return $this->repondre($request, 'Quantité mise à jour.', 'app_panier');
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
    private function repondre(Request $request, string $message, string $route): Response
    {
        if ($request->isXmlHttpRequest()) {
            return $this->json([
                'message' => $message,
                'nombre' => $this->cart->count(),
                'total' => $this->cart->total(),
            ]);
        }

        $this->addFlash('panier', $message);

        return $this->redirectToRoute($route);
    }
}
