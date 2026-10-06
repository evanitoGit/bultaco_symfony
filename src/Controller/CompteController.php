<?php

namespace App\Controller;

use App\Commande\CommandeService;
use App\Entity\Client;
use App\Form\InscriptionType;
use App\Form\MotDePasseType;
use App\Form\ProfilType;
use App\Security\ClientAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Espace client : connexion, inscription, commandes et informations du compte.
 * L'accès à /compte (hors connexion et inscription) est réservé à ROLE_CLIENT (security.yaml).
 */
#[Route('/compte')]
class CompteController extends AbstractController
{
    use TargetPathTrait;

    #[Route('/connexion', name: 'app_compte_connexion')]
    public function connexion(Request $request, AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() instanceof Client) {
            return $this->redirectToRoute('app_compte');
        }

        // Lien « Se connecter » de la page de commande : on y revient après la connexion
        if ($request->query->get('retour') === 'commande') {
            $this->saveTargetPath($request->getSession(), 'main', $this->generateUrl('app_commande'));
        }

        return $this->render('compte/connexion.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/inscription', name: 'app_compte_inscription', methods: ['GET', 'POST'])]
    public function inscription(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        Security $security,
    ): Response {
        if ($this->getUser() instanceof Client) {
            return $this->redirectToRoute('app_compte');
        }

        $client = new Client();
        // e-mail pré-rempli depuis la page de confirmation d'une commande
        $client->setEmail($request->query->getString('email') ?: null);

        $form = $this->createForm(InscriptionType::class, $client);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $client->setPassword($passwordHasher->hashPassword($client, $form->get('plainPassword')->getData()));
            $client->setDerniereConnexion(new \DateTimeImmutable());
            $entityManager->persist($client);
            $entityManager->flush();

            // Connexion automatique après l'inscription (le panier en session est conservé)
            $security->login($client, ClientAuthenticator::class, 'main');
            $this->addFlash('compte', sprintf('Bienvenue %s, votre compte a bien été créé.', $client->getPrenom()));

            return $request->query->get('retour') === 'commande'
                ? $this->redirectToRoute('app_commande')
                : $this->redirectToRoute('app_compte');
        }

        return $this->render('compte/inscription.html.twig', ['form' => $form]);
    }

    #[Route('', name: 'app_compte', methods: ['GET'])]
    public function index(CommandeService $commandes): Response
    {
        return $this->render('compte/index.html.twig', [
            'client' => $this->client(),
            'commandes' => $commandes->commandesDuClient($this->client()->getId()),
            'statuts' => CommandeService::libellesStatuts(),
        ]);
    }

    #[Route('/commandes/{reference}', name: 'app_compte_commande', methods: ['GET'])]
    public function commande(string $reference, CommandeService $commandes): Response
    {
        // Introuvable si la commande appartient à un autre client
        $commande = $commandes->trouverPourClient($reference, $this->client()->getId());
        if ($commande === null) {
            throw $this->createNotFoundException('Commande introuvable.');
        }

        return $this->render('compte/commande.html.twig', [
            'commande' => $commande,
            'statuts' => CommandeService::libellesStatuts(),
        ]);
    }

    #[Route('/informations', name: 'app_compte_informations', methods: ['GET', 'POST'])]
    public function informations(Request $request, EntityManagerInterface $entityManager): Response
    {
        $client = $this->client();
        $form = $this->createForm(ProfilType::class, $client);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();
            $this->addFlash('compte', 'Vos informations ont été enregistrées.');

            return $this->redirectToRoute('app_compte_informations');
        }

        if ($form->isSubmitted()) {
            // formulaire invalide : on ne garde pas les valeurs erronées sur l'utilisateur connecté
            $entityManager->refresh($client);
        }

        return $this->render('compte/informations.html.twig', ['form' => $form, 'client' => $client]);
    }

    #[Route('/mot-de-passe', name: 'app_compte_mot_de_passe', methods: ['GET', 'POST'])]
    public function motDePasse(Request $request, UserPasswordHasherInterface $passwordHasher, EntityManagerInterface $entityManager): Response
    {
        $client = $this->client();
        $form = $this->createForm(MotDePasseType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $client->setPassword($passwordHasher->hashPassword($client, $form->get('nouveau')->getData()));
            $entityManager->flush();
            $this->addFlash('compte', 'Votre mot de passe a été modifié.');

            return $this->redirectToRoute('app_compte');
        }

        return $this->render('compte/mot_de_passe.html.twig', ['form' => $form]);
    }

    private function client(): Client
    {
        $client = $this->getUser();
        if (!$client instanceof Client) {
            throw $this->createAccessDeniedException();
        }

        return $client;
    }
}
