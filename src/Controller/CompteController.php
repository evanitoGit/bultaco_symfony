<?php

namespace App\Controller;

use App\Commande\CommandeService;
use App\Compte\CompteService;
use App\Compte\ReinitialisationMotDePasse;
use App\Entity\Client;
use App\Form\ChangementEmailType;
use App\Form\InscriptionType;
use App\Form\MotDePasseOublieType;
use App\Form\MotDePasseType;
use App\Form\NouveauMotDePasseType;
use App\Form\ProfilType;
use App\Form\SuppressionCompteType;
use App\Repository\ClientRepository;
use App\Security\ClientAuthenticator;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Espace client : connexion, inscription, vérification de l'e-mail, mot de passe oublié,
 * commandes, informations du compte et données personnelles.
 * L'accès à /compte (hors pages publiques) est réservé à ROLE_CLIENT (security.yaml).
 */
#[Route('/compte')]
class CompteController extends AbstractController
{
    use TargetPathTrait;

    /** Jeton « mot de passe oublié » gardé en session (retiré de l'URL dès l'arrivée) */
    private const SESSION_JETON = 'reinitialisation_jeton';

    public function __construct(private readonly CompteService $comptes)
    {
    }

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

            // Connexion automatique après l'inscription (le panier en session est fusionné dans le compte)
            $security->login($client, ClientAuthenticator::class, 'main');
            $this->addFlash('compte', sprintf('Bienvenue %s, votre compte a bien été créé.', $client->getPrenom()));

            if ($this->comptes->envoyerVerification($client)) {
                $this->addFlash('compte', sprintf('Un e-mail de confirmation vient d\'être envoyé à %s.', $client->getEmail()));
            }
            $anciennes = $this->comptes->nbCommandesSansCompte($client->getEmail());
            if ($anciennes > 0) {
                $this->addFlash('compte', $anciennes === 1
                    ? 'Une commande passée avec cette adresse sera ajoutée à votre compte dès que vous l\'aurez confirmée.'
                    : sprintf('%d commandes passées avec cette adresse seront ajoutées à votre compte dès que vous l\'aurez confirmée.', $anciennes));
            }

            return $request->query->get('retour') === 'commande'
                ? $this->redirectToRoute('app_commande')
                : $this->redirectToRoute('app_compte');
        }

        return $this->render('compte/inscription.html.twig', ['form' => $form]);
    }

    // ---------- Vérification de l'adresse e-mail ----------

    #[Route('/verifier-email', name: 'app_compte_verifier_email', methods: ['GET'])]
    public function verifierEmail(Request $request): Response
    {
        $client = $this->comptes->clientDuLien($request);
        $connecte = $this->getUser() instanceof Client;

        if ($client === null) {
            $this->addFlash('compte_alerte', 'Ce lien de confirmation est invalide ou a expiré. '
                . ($connecte ? 'Demandez-en un nouveau ci-dessous.' : 'Connectez-vous pour en demander un nouveau.'));
        } elseif ($client->isEmailVerifie()) {
            $this->addFlash('compte', 'Votre adresse e-mail est déjà confirmée.');
        } else {
            $rattachees = $this->comptes->marquerVerifie($client);
            $this->addFlash('compte', 'Merci, votre adresse e-mail est confirmée.' . match (true) {
                $rattachees === 1 => ' Votre commande passée sans compte a été ajoutée à votre historique.',
                $rattachees > 1 => sprintf(' Vos %d commandes passées sans compte ont été ajoutées à votre historique.', $rattachees),
                default => '',
            });
        }

        return $this->redirectToRoute($connecte ? 'app_compte' : 'app_compte_connexion');
    }

    #[Route('/verification/renvoyer', name: 'app_compte_renvoyer_verification', methods: ['POST'])]
    public function renvoyerVerification(Request $request, RateLimiterFactoryInterface $renvoiVerificationLimiter): Response
    {
        $client = $this->client();
        $retour = $this->redirectToRoute('app_compte');

        if (!$this->isCsrfTokenValid('renvoyer_verification', $request->getPayload()->getString('_token'))) {
            $this->addFlash('compte_alerte', 'La demande a expiré, veuillez réessayer.');

            return $retour;
        }
        if ($client->isEmailVerifie()) {
            return $retour;
        }
        if (!$renvoiVerificationLimiter->create('client-' . $client->getId())->consume()->isAccepted()) {
            $this->addFlash('compte_alerte', 'Trop de demandes : patientez un peu avant de redemander un e-mail.');

            return $retour;
        }

        $this->comptes->envoyerVerification($client)
            ? $this->addFlash('compte', sprintf('Un nouvel e-mail de confirmation a été envoyé à %s.', $client->getEmail()))
            : $this->addFlash('compte_alerte', 'L\'e-mail n\'a pas pu être envoyé, réessayez plus tard.');

        return $retour;
    }

    // ---------- Mot de passe oublié ----------

    #[Route('/mot-de-passe-oublie', name: 'app_compte_mot_de_passe_oublie', methods: ['GET', 'POST'])]
    public function motDePasseOublie(Request $request, ReinitialisationMotDePasse $reinitialisation, RateLimiterFactoryInterface $motDePasseOublieLimiter): Response
    {
        $form = $this->createForm(MotDePasseOublieType::class, ['email' => $request->query->getString('email') ?: null]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            if (!$motDePasseOublieLimiter->create($request->getClientIp())->consume()->isAccepted()) {
                $form->addError(new FormError('Trop de demandes depuis votre connexion : réessayez dans une heure.'));
            } else {
                $reinitialisation->demander($form->get('email')->getData());
                // Même message que l'adresse soit connue ou non
                $this->addFlash('compte', 'Si un compte existe avec cette adresse, vous allez recevoir un e-mail avec un lien pour choisir un nouveau mot de passe (valable 1 heure).');

                return $this->redirectToRoute('app_compte_connexion');
            }
        }

        return $this->render('compte/mot_de_passe_oublie.html.twig', ['form' => $form]);
    }

    #[Route('/reinitialiser/{jeton}', name: 'app_compte_reinitialiser', defaults: ['jeton' => null], methods: ['GET', 'POST'])]
    public function reinitialiser(
        ?string $jeton,
        Request $request,
        ReinitialisationMotDePasse $reinitialisation,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
    ): Response {
        $session = $request->getSession();
        if ($jeton !== null) {
            // le jeton quitte l'URL (historique, en-tête Referer…)
            $session->set(self::SESSION_JETON, $jeton);

            return $this->redirectToRoute('app_compte_reinitialiser');
        }

        $client = $reinitialisation->clientDuJeton($session->get(self::SESSION_JETON));
        if ($client === null) {
            $session->remove(self::SESSION_JETON);
            $this->addFlash('compte_alerte', 'Ce lien de réinitialisation est invalide ou a expiré. Faites une nouvelle demande.');

            return $this->redirectToRoute('app_compte_mot_de_passe_oublie');
        }

        $form = $this->createForm(NouveauMotDePasseType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $client->setPassword($passwordHasher->hashPassword($client, $form->get('nouveau')->getData()));
            // le lien est arrivé dans sa boîte mail : l'adresse est donc confirmée
            $client->setEmailVerifie(true);
            $entityManager->flush();
            $this->comptes->rattacherCommandes($client);
            $reinitialisation->terminer($client);
            $session->remove(self::SESSION_JETON);

            $this->addFlash('compte', 'Votre mot de passe a été modifié, vous pouvez vous connecter.');

            return $this->redirectToRoute('app_compte_connexion');
        }

        return $this->render('compte/reinitialiser.html.twig', ['form' => $form, 'email' => $client->getEmail()]);
    }

    // ---------- Espace client ----------

    #[Route('', name: 'app_compte', methods: ['GET'])]
    public function index(CommandeService $commandes): Response
    {
        $client = $this->client();

        return $this->render('compte/index.html.twig', [
            'client' => $client,
            'commandes' => $commandes->commandesDuClient($client->getId()),
            'statuts' => CommandeService::libellesStatuts(),
            // commandes en attente de rattachement (adresse pas encore confirmée)
            'sans_compte' => $client->isEmailVerifie() ? 0 : $this->comptes->nbCommandesSansCompte($client->getEmail()),
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
    public function informations(Request $request, EntityManagerInterface $entityManager, ClientRepository $clients): Response
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

        $formEmail = $this->createForm(ChangementEmailType::class);
        $formEmail->handleRequest($request);

        if ($formEmail->isSubmitted() && $formEmail->isValid()) {
            $email = mb_strtolower(trim($formEmail->get('email')->getData()));

            if ($email === $client->getEmail()) {
                $formEmail->get('email')->addError(new FormError('C\'est déjà l\'adresse de votre compte.'));
            } elseif ($clients->findOneBy(['email' => $email]) !== null) {
                $formEmail->get('email')->addError(new FormError('Cette adresse est déjà utilisée par un autre compte.'));
            } else {
                $client->setEmail($email);
                $client->setEmailVerifie(false);
                $entityManager->flush();
                $this->comptes->envoyerVerification($client);
                $this->addFlash('compte', sprintf('Votre adresse e-mail est maintenant %s. Cliquez sur le lien que nous venons de vous envoyer pour la confirmer.', $email));

                return $this->redirectToRoute('app_compte_informations');
            }
        }

        return $this->render('compte/informations.html.twig', [
            'form' => $form,
            'form_email' => $formEmail,
            'client' => $client,
        ]);
    }

    #[Route('/mot-de-passe', name: 'app_compte_mot_de_passe', methods: ['GET', 'POST'])]
    public function motDePasse(
        Request $request,
        UserPasswordHasherInterface $passwordHasher,
        EntityManagerInterface $entityManager,
        ReinitialisationMotDePasse $reinitialisation,
    ): Response {
        $client = $this->client();
        $form = $this->createForm(MotDePasseType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $client->setPassword($passwordHasher->hashPassword($client, $form->get('nouveau')->getData()));
            $entityManager->flush();
            // un éventuel lien « mot de passe oublié » encore valide ne sert plus
            $reinitialisation->terminer($client);
            $this->addFlash('compte', 'Votre mot de passe a été modifié.');

            return $this->redirectToRoute('app_compte');
        }

        return $this->render('compte/mot_de_passe.html.twig', ['form' => $form]);
    }

    // ---------- Mes données (RGPD) ----------

    #[Route('/donnees', name: 'app_compte_donnees', methods: ['GET', 'POST'])]
    public function donnees(Request $request, Security $security): Response
    {
        $client = $this->client();
        $form = $this->createForm(SuppressionCompteType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->comptes->supprimer($client);
            // déconnexion (session invalidée, cookie « rester connecté » effacé)
            $security->logout(false);
            $this->addFlash('compte', 'Votre compte a été supprimé. Vos commandes restent consultables par le club pour la comptabilité. À bientôt !');

            return $this->redirectToRoute('app_compte_connexion');
        }

        return $this->render('compte/donnees.html.twig', ['form' => $form, 'client' => $client]);
    }

    #[Route('/donnees/export', name: 'app_compte_export', methods: ['GET'])]
    public function export(): Response
    {
        $response = new JsonResponse($this->comptes->exporter($this->client()));
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->headers->set('Content-Disposition', $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'mes-donnees-bultaco.json'));
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
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
