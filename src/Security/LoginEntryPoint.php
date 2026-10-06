<?php

namespace App\Security;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * Visiteur non connecté sur une page protégée : connexion administrateur pour /admin,
 * connexion client pour le reste (ex. /compte).
 */
final class LoginEntryPoint implements AuthenticationEntryPointInterface
{
    public function __construct(private readonly UrlGeneratorInterface $urlGenerator)
    {
    }

    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        $route = str_starts_with($request->getPathInfo(), '/admin') ? AppAuthAuthenticator::LOGIN_ROUTE : ClientAuthenticator::LOGIN_ROUTE;

        return new RedirectResponse($this->urlGenerator->generate($route));
    }
}
