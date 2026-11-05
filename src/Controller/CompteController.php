<?php

namespace App\Controller;

use App\Boutique\Catalogue;
use App\Boutique\DemandePiece;
use App\Commande\CommandeService;
use App\Compte\CompteService;
use App\Compte\Garage;
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
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Espace client : connexion, inscription, vérification de l'e-mail, mot de passe oublié,
 * commandes, demandes de pièces, informations du compte et données personnelles.
 * L'accès à /compte (hors pages publiques) est réservé à ROLE_CLIENT (security.yaml).
 */
#[Route('/compte')]
class CompteController extends AbstractController
{
    use TargetPathTrait;

    /** Jeton « mot de passe oublié » gardé en session (retiré de l'URL dès l'arrivée) */
    private const SESSION_JETON = 'reinitialisation_jeton';

    public function __construct(
        private readonly CompteService $comptes,
        private readonly TranslatorInterface $translator,
    ) {
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
            $client->setLangue($request->getLocale());
            $entityManager->persist($client);
            $entityManager->flush();

            // Connexion automatique après l'inscription (le panier en session est fusionné dans le compte)
            $security->login($client, ClientAuthenticator::class, 'main');
            $this->addFlash('compte', $this->t('compte.flash.bienvenue', ['prenom' => $client->getPrenom()]));

            if ($this->comptes->envoyerVerification($client)) {
                $this->addFlash('compte', $this->t('compte.flash.verification_envoyee', ['email' => $client->getEmail()]));
            }
            $anciennes = $this->comptes->nbCommandesSansCompte($client->getEmail());
            if ($anciennes > 0) {
                $this->addFlash('compte', $this->t('compte.flash.commandes_a_rattacher', ['n' => $anciennes]));
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
            $this->addFlash('compte_alerte', $this->t($connecte ? 'compte.flash.lien_verification_invalide_connecte' : 'compte.flash.lien_verification_invalide'));
        } elseif ($client->isEmailVerifie()) {
            $this->addFlash('compte', $this->t('compte.flash.deja_confirmee'));
        } else {
            $rattachees = $this->comptes->marquerVerifie($client);
            $this->addFlash('compte', $this->t('compte.flash.confirmee', ['n' => $rattachees]));
        }

        return $this->redirectToRoute($connecte ? 'app_compte' : 'app_compte_connexion');
    }

    #[Route('/verification/renvoyer', name: 'app_compte_renvoyer_verification', methods: ['POST'])]
    public function renvoyerVerification(Request $request, RateLimiterFactoryInterface $renvoiVerificationLimiter): Response
    {
        $client = $this->client();
        $retour = $this->redirectToRoute('app_compte');

        if (!$this->isCsrfTokenValid('renvoyer_verification', $request->getPayload()->getString('_token'))) {
            $this->addFlash('compte_alerte', $this->t('commun.session_expiree'));

            return $retour;
        }
        if ($client->isEmailVerifie()) {
            return $retour;
        }
        if (!$renvoiVerificationLimiter->create('client-' . $client->getId())->consume()->isAccepted()) {
            $this->addFlash('compte_alerte', $this->t('compte.flash.trop_de_renvois'));

            return $retour;
        }

        $this->comptes->envoyerVerification($client)
            ? $this->addFlash('compte', $this->t('compte.flash.verification_renvoyee', ['email' => $client->getEmail()]))
            : $this->addFlash('compte_alerte', $this->t('compte.flash.envoi_impossible'));

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
                $form->addError(new FormError($this->t('compte.flash.trop_de_demandes')));
            } else {
                $reinitialisation->demander($form->get('email')->getData());
                // Même message que l'adresse soit connue ou non
                $this->addFlash('compte', $this->t('compte.flash.lien_reinitialisation_envoye'));

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
            $this->addFlash('compte_alerte', $this->t('compte.flash.lien_reinitialisation_invalide'));

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

            $this->addFlash('compte', $this->t('compte.flash.mot_de_passe_reinitialise'));

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
        ]);
    }

    #[Route('/demandes', name: 'app_compte_demandes', methods: ['GET'])]
    public function demandes(DemandePiece $demandes): Response
    {
        return $this->render('compte/demandes.html.twig', [
            'demandes' => $demandes->demandesDuClient($this->client()),
        ]);
    }

    /**
     * « Mon garage » : les motos du client, proposées en un clic dans la boutique,
     * avec un e-mail quand une pièce compatible est ajoutée.
     */
    #[Route('/garage', name: 'app_compte_garage', methods: ['GET', 'POST'])]
    public function garage(Request $request, Garage $garage, Catalogue $catalogue): Response
    {
        $client = $this->client();

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('garage', $request->getPayload()->getString('_token'))) {
                $this->addFlash('compte_alerte', $this->t('commun.session_expiree'));

                return $this->redirectToRoute('app_compte_garage');
            }
            // champs facultatifs ou laissés vides : 0 plutôt qu'une erreur
            $entier = fn (string $champ): int => (int) filter_var($request->getPayload()->getString($champ), \FILTER_VALIDATE_INT, ['options' => ['default' => 0]]);
            $moto = $entier('moto');
            switch ($request->getPayload()->getString('action')) {
                case 'ajouter':
                    $annee = $entier('annee');
                    $annee = $annee >= Garage::ANNEE_MIN && $annee <= (int) date('Y') ? $annee : null;
                    $ajoutee = $garage->ajouter($client->getId(), $entier('modele'), $annee, $request->getPayload()->getString('surnom'));
                    $ajoutee
                        ? $this->addFlash('compte', $this->t('garage.flash.ajoutee'))
                        : $this->addFlash('compte_alerte', $this->t('garage.flash.impossible', ['max' => Garage::MAX]));
                    break;
                case 'retirer':
                    $garage->retirer($client->getId(), $moto);
                    $this->addFlash('compte', $this->t('garage.flash.retiree'));
                    break;
                case 'alertes':
                    $actives = $request->getPayload()->getBoolean('alertes');
                    $garage->alertes($client->getId(), $moto, $actives);
                    $this->addFlash('compte', $this->t($actives ? 'garage.flash.alertes_on' : 'garage.flash.alertes_off'));
                    break;
            }

            return $this->redirectToRoute('app_compte_garage');
        }

        return $this->render('compte/garage.html.twig', [
            'motos' => $garage->motos($client->getId()),
            'modeles_moto' => $catalogue->modelesMoto(),
            'max' => Garage::MAX,
            'annee_min' => Garage::ANNEE_MIN,
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
            $this->addFlash('compte', $this->t('compte.flash.informations_enregistrees'));

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
                $formEmail->get('email')->addError(new FormError($this->t('compte.flash.meme_adresse')));
            } elseif ($clients->findOneBy(['email' => $email]) !== null) {
                $formEmail->get('email')->addError(new FormError($this->t('compte.flash.adresse_utilisee')));
            } else {
                $client->setEmail($email);
                $client->setEmailVerifie(false);
                $entityManager->flush();
                $this->comptes->envoyerVerification($client);
                $this->addFlash('compte', $this->t('compte.flash.email_change', ['email' => $email]));

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
            $this->addFlash('compte', $this->t('compte.flash.mot_de_passe_modifie'));

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
            $this->addFlash('compte', $this->t('compte.flash.compte_supprime'));

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

    private function t(string $cle, array $parametres = []): string
    {
        return $this->translator->trans($cle, $parametres);
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
