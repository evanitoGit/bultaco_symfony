<?php

namespace App\Controller;

use App\Cart\Cart;
use App\Commande\CommandeService;
use App\Commande\StockInsuffisantException;
use App\Entity\Client;
use App\Form\CommandeType;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/commande')]
class CommandeController extends AbstractController
{
    #[Route('', name: 'app_commande', methods: ['GET', 'POST'])]
    public function index(Request $request, Cart $cart, CommandeService $commandes): Response
    {
        $lignes = $cart->lignes();
        if ($lignes === []) {
            $this->addFlash('panier', 'Votre panier est vide.');

            return $this->redirectToRoute('app_panier');
        }
        if ($cart->ajustements() !== []) {
            // stock en baisse depuis l'ajout : le client vérifie son panier corrigé avant de commander
            return $this->redirectToRoute('app_panier');
        }

        // Client connecté : formulaire pré-rempli avec les informations de son compte
        $compte = $this->getUser() instanceof Client ? $this->getUser() : null;
        $donnees = ['pays' => 'France', 'mode_paiement' => 'virement'];
        if ($compte !== null) {
            $donnees = [
                'prenom' => $compte->getPrenom(),
                'nom' => $compte->getNom(),
                'email' => $compte->getEmail(),
                'telephone' => $compte->getTelephone(),
                'adresse' => $compte->getAdresse(),
                'code_postal' => $compte->getCodePostal(),
                'ville' => $compte->getVille(),
                'pays' => $compte->getPays() ?: 'France',
            ] + $donnees;
        }

        $form = $this->createForm(CommandeType::class, $donnees);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $commande = $commandes->creer($form->getData(), $compte);
            } catch (StockInsuffisantException $e) {
                $this->addFlash('panier', $e->disponible > 0
                    ? sprintf('Désolé, il ne reste que %d « %s » en stock : un autre client vient d\'en commander. Vérifiez votre panier avant de valider.', $e->disponible, $e->piece)
                    : sprintf('Désolé, « %s » vient d\'être commandé par un autre client et n\'est plus disponible. Vérifiez votre panier avant de valider.', $e->piece));

                return $this->redirectToRoute('app_panier');
            }
            // seule cette session pourra afficher la page de confirmation
            $request->getSession()->set('derniere_commande', $commande['id']);

            return $this->redirectToRoute('app_commande_merci');
        }

        return $this->render('commande/index.html.twig', [
            'form' => $form,
            'compte' => $compte,
            'lignes' => $lignes,
            'total' => $cart->total(),
        ]);
    }

    #[Route('/merci', name: 'app_commande_merci', methods: ['GET'])]
    public function merci(Request $request, CommandeService $commandes): Response
    {
        $id = $request->getSession()->get('derniere_commande');
        $commande = $id ? $commandes->trouver($id) : null;

        if ($commande === null) {
            return $this->redirectToRoute('app_boutique_pieces');
        }

        return $this->render('commande/merci.html.twig', ['commande' => $commande]);
    }
}
