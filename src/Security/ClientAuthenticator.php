<?php

namespace App\Security;

use App\Entity\Client;
use App\Repository\ClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Http\Authenticator\AbstractLoginFormAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\CsrfTokenBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\RememberMeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\SecurityRequestAttributes;
use Symfony\Component\Security\Http\Util\TargetPathTrait;

/**
 * Connexion des clients de la boutique (/compte/connexion, par adresse e-mail).
 */
class ClientAuthenticator extends AbstractLoginFormAuthenticator
{
    use TargetPathTrait;

    public const LOGIN_ROUTE = 'app_compte_connexion';

    public function __construct(
        private UrlGeneratorInterface $urlGenerator,
        private ClientRepository $clients,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function authenticate(Request $request): Passport
    {
        $email = mb_strtolower(trim($request->getPayload()->getString('email')));

        $request->getSession()->set(SecurityRequestAttributes::LAST_USERNAME, $email);

        return new Passport(
            // cherche uniquement dans la table `clients`
            new UserBadge($email, fn (string $identifiant) => $this->clients->findOneBy(['email' => $identifiant])),
            new PasswordCredentials($request->getPayload()->getString('password')),
            [
                new CsrfTokenBadge('authenticate', $request->getPayload()->getString('_csrf_token')),
                new RememberMeBadge(),
            ]
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $client = $token->getUser();
        if ($client instanceof Client) {
            $client->setDerniereConnexion(new \DateTimeImmutable());
            $client->setLangue($request->getLocale());
            $this->entityManager->flush();
        }

        // Retour vers la page demandée avant la connexion (ex. /commande), jamais vers l'administration
        $targetPath = $this->getTargetPath($request->getSession(), $firewallName);
        if ($targetPath && !str_starts_with(parse_url($targetPath, PHP_URL_PATH) ?? '', '/admin')) {
            return new RedirectResponse($targetPath);
        }

        return new RedirectResponse($this->urlGenerator->generate('app_compte'));
    }

    protected function getLoginUrl(Request $request): string
    {
        return $this->urlGenerator->generate(self::LOGIN_ROUTE);
    }
}
