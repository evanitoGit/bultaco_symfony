<?php

namespace App\EventListener;

use App\I18n\Contenu;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Page d'erreur dans la langue de l'adresse demandée : /en/page-inexistante -> page 404 en anglais.
 * (Quand aucune route ne correspond, le routeur n'a pas pu fixer la langue.)
 */
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: 10)]
final class LangueErreurListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $request = $event->getRequest();
        if ($request->attributes->has('_locale')) {
            return;
        }
        if (preg_match('#^/(' . implode('|', array_keys(Contenu::TRADUCTIONS)) . ')(/|$)#', $request->getPathInfo(), $langue)) {
            $request->setLocale($langue[1]);
            $request->attributes->set('_locale', $langue[1]);
        }
    }
}
