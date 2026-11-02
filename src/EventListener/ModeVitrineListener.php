<?php

namespace App\EventListener;

use App\Boutique\Reglages;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Boutique en mode vitrine : panier, commande et alertes de stock sont fermés,
 * même si quelqu'un appelle directement leur adresse.
 */
#[AsEventListener(event: KernelEvents::REQUEST)]
final class ModeVitrineListener
{
    private const ROUTES_FERMEES = [
        'app_panier', 'app_panier_apercu', 'app_panier_ajouter', 'app_panier_quantite',
        'app_panier_retirer', 'app_panier_vider', 'app_commande', 'app_alerte_stock',
    ];

    public function __construct(
        private readonly Reglages $reglages,
        private readonly UrlGeneratorInterface $urls,
        private readonly TranslatorInterface $translator,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        $request = $event->getRequest();
        // routes traduites : « app_panier.en » -> « app_panier »
        $route = $request->attributes->get('_canonical_route') ?? $request->attributes->get('_route');

        if (!$event->isMainRequest() || !in_array($route, self::ROUTES_FERMEES, true) || $this->reglages->venteOuverte()) {
            return;
        }

        $message = $this->translator->trans('vitrine.vente_suspendue');

        // ajout au panier en JavaScript (panier.js attend du JSON)
        if ($request->isXmlHttpRequest()) {
            $event->setResponse(new JsonResponse(['ajoute' => false, 'message' => $message, 'nombre' => 0], 403));

            return;
        }

        if ($request->hasSession()) {
            $request->getSession()->getFlashBag()->add('panier', $message);
        }
        $event->setResponse(new RedirectResponse($this->urls->generate('app_boutique_pieces')));
    }
}
