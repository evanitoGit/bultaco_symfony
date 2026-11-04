<?php

namespace App\Controller;

use App\Cart\Cart;
use App\Commande\CommandeService;
use App\Commande\StockInsuffisantException;
use App\Entity\Client;
use App\Form\CommandeType;
use App\Paiement\PaiementCarte;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Http\Attribute\IsCsrfTokenValid;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

#[Route('/commande')]
class CommandeController extends AbstractController
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    #[Route('', name: 'app_commande', methods: ['GET', 'POST'])]
    public function index(Request $request, Cart $cart, CommandeService $commandes, PaiementCarte $carte, TranslatorInterface $translator): Response
    {
        $lignes = $cart->lignes();
        if ($lignes === []) {
            $this->addFlash('panier', $translator->trans('commande.flash.panier_vide'));

            return $this->redirectToRoute('app_panier');
        }
        if ($cart->ajustements() !== []) {
            // stock en baisse depuis l'ajout : le client vérifie son panier corrigé avant de commander
            return $this->redirectToRoute('app_panier');
        }

        // Client connecté : formulaire pré-rempli avec les informations de son compte
        $compte = $this->getUser() instanceof Client ? $this->getUser() : null;
        $modes = $carte->modesDisponibles();
        $donnees = ['pays' => $translator->trans('pied.pays'), 'mode_paiement' => reset($modes)];
        if ($compte !== null) {
            $donnees = [
                'prenom' => $compte->getPrenom(),
                'nom' => $compte->getNom(),
                'email' => $compte->getEmail(),
                'telephone' => $compte->getTelephone(),
                'adresse' => $compte->getAdresse(),
                'code_postal' => $compte->getCodePostal(),
                'ville' => $compte->getVille(),
                'pays' => $compte->getPays() ?: $translator->trans('pied.pays'),
            ] + $donnees;
        }

        $form = $this->createForm(CommandeType::class, $donnees, ['paiements' => $modes]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            try {
                $commande = $commandes->creer($form->getData(), $compte);
            } catch (StockInsuffisantException $e) {
                $this->addFlash('panier', $translator->trans('commande.flash.stock_insuffisant', ['disponible' => $e->disponible, 'piece' => $e->piece]));

                return $this->redirectToRoute('app_panier');
            }
            // seule cette session pourra afficher la page de confirmation
            $request->getSession()->set('derniere_commande', $commande['id']);

            if ($commande['mode_paiement'] === 'carte') {
                return $this->versStripe($commande, $carte, $translator);
            }

            return $this->redirectToRoute('app_commande_merci');
        }

        return $this->render('commande/index.html.twig', [
            'form' => $form,
            'carte' => $carte->actif(),
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

    /**
     * Retour depuis la page de paiement Stripe. Le paiement est vérifié auprès de Stripe :
     * l'adresse seule ne prouve rien, n'importe qui pourrait la saisir.
     */
    #[Route('/paiement/{reference}/retour', name: 'app_commande_paiement_retour', methods: ['GET'])]
    public function retourPaiement(string $reference, Request $request, CommandeService $commandes, PaiementCarte $carte): Response
    {
        $commande = $this->commandeDeLaSession($reference, $request, $commandes);
        if ($commande === null) {
            return $this->redirectToRoute('app_boutique_pieces');
        }

        try {
            $carte->verifier($commande);
        } catch (\Throwable $e) {
            // Stripe injoignable : la notification (webhook) confirmera le paiement de son côté
            $this->logger->error('Vérification du paiement par carte impossible', ['reference' => $reference, 'exception' => $e]);
        }

        return $this->redirectToRoute('app_commande_merci');
    }

    /** Le client a quitté la page de paiement Stripe sans payer */
    #[Route('/paiement/{reference}/annule', name: 'app_commande_paiement_annule', methods: ['GET'])]
    public function paiementAnnule(string $reference, Request $request, CommandeService $commandes): Response
    {
        $commande = $this->commandeDeLaSession($reference, $request, $commandes);
        if ($commande === null) {
            return $this->redirectToRoute('app_boutique_pieces');
        }
        if ($commande['date_paiement'] !== null || $commande['statut'] !== 'en_attente') {
            // payée entre-temps (autre onglet) ou déjà annulée : rien à reprendre
            return $this->redirectToRoute($commande['date_paiement'] !== null ? 'app_commande_merci' : 'app_panier');
        }

        return $this->render('commande/paiement_annule.html.twig', ['commande' => $commande]);
    }

    /** Nouvelle tentative de paiement par carte pour la même commande */
    #[Route('/paiement/{reference}/relancer', name: 'app_commande_paiement_relancer', methods: ['POST'])]
    #[IsCsrfTokenValid('paiement', tokenKey: '_token')]
    public function relancerPaiement(string $reference, Request $request, CommandeService $commandes, PaiementCarte $carte, TranslatorInterface $translator): Response
    {
        $commande = $this->commandeDeLaSession($reference, $request, $commandes);
        if ($commande === null || $commande['statut'] !== 'en_attente' || $commande['date_paiement'] !== null) {
            return $this->redirectToRoute('app_commande_merci');
        }

        return $this->versStripe($commande, $carte, $translator);
    }

    /** Abandon du paiement par carte : commande annulée, panier rendu pour choisir un autre mode de paiement */
    #[Route('/paiement/{reference}/autre-mode', name: 'app_commande_paiement_autre_mode', methods: ['POST'])]
    #[IsCsrfTokenValid('paiement', tokenKey: '_token')]
    public function autreMode(string $reference, Request $request, CommandeService $commandes, PaiementCarte $carte): Response
    {
        $commande = $this->commandeDeLaSession($reference, $request, $commandes);
        if ($commande !== null) {
            $carte->abandonner($commande);
            $request->getSession()->remove('derniere_commande');
        }

        return $this->redirectToRoute('app_commande');
    }

    private function versStripe(array $commande, PaiementCarte $carte, TranslatorInterface $translator): Response
    {
        try {
            // 303 : le navigateur suit la redirection en GET après le formulaire
            return $this->redirect($carte->demarrer($commande), Response::HTTP_SEE_OTHER);
        } catch (\Throwable $e) {
            $this->logger->error('Ouverture du paiement par carte impossible', ['reference' => $commande['reference'], 'exception' => $e]);
            $carte->abandonner($commande);
            $message = $translator->trans('commande.flash.carte_indisponible');
            if ($this->getParameter('kernel.debug')) {
                $message .= ' [dev] ' . $e->getMessage(); // raison technique, seulement en développement
            }
            $this->addFlash('panier', $message);

            return $this->redirectToRoute('app_commande');
        }
    }

    /** Seul le navigateur qui a passé la commande peut en suivre le paiement */
    private function commandeDeLaSession(string $reference, Request $request, CommandeService $commandes): ?array
    {
        $id = $request->getSession()->get('derniere_commande');
        $commande = $id ? $commandes->trouver($id) : null;

        return $commande !== null && $commande['reference'] === $reference && $commande['mode_paiement'] === 'carte' ? $commande : null;
    }
}
