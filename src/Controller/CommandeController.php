<?php

namespace App\Controller;

use App\Cart\Cart;
use App\Commande\CommandeService;
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
            $commande = $commandes->creer($form->getData(), $compte);
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
