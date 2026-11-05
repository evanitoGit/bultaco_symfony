<?php

namespace App\EventListener;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * En-têtes de sécurité ajoutés à toutes les pages :
 * - le site ne peut pas être affiché dans le cadre d'un autre site (faux site qui ferait cliquer à l'aveugle) ;
 * - le navigateur ne devine pas le type d'un fichier (une image piégée reste une image) ;
 * - l'adresse complète des pages n'est pas transmise aux autres sites ;
 * - la caméra, le micro et la localisation ne sont jamais demandés ;
 * - en HTTPS, le navigateur retient de ne plus jamais passer par HTTP.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final class EnTetesSecuriteListener
{
    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $entetes = $event->getResponse()->headers;
        $ajouter = static function (string $nom, string $valeur) use ($entetes): void {
            if (!$entetes->has($nom)) {
                $entetes->set($nom, $valeur);
            }
        };

        $ajouter('X-Frame-Options', 'SAMEORIGIN');
        $ajouter('X-Content-Type-Options', 'nosniff');
        $ajouter('Referrer-Policy', 'strict-origin-when-cross-origin');
        $ajouter('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        // Volontairement souple : les scripts du site restent autorisés tels quels ;
        // on interdit seulement l'affichage dans un cadre externe, les plugins et le détournement des liens relatifs.
        $ajouter('Content-Security-Policy', "frame-ancestors 'self'; object-src 'none'; base-uri 'self'");

        if ($event->getRequest()->isSecure()) {
            $ajouter('Strict-Transport-Security', 'max-age=31536000');
        }

        // Ne pas annoncer la version de PHP (mieux : expose_php = Off dans php.ini)
        $entetes->remove('X-Powered-By');
        if (!headers_sent()) {
            header_remove('X-Powered-By');
        }
    }
}
