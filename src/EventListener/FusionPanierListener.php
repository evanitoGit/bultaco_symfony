<?php

namespace App\EventListener;

use App\Cart\Cart;
use App\Entity\Client;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;

/**
 * Connexion d'un client (formulaire, « rester connecté » ou inscription) :
 * le panier du visiteur rejoint celui du compte.
 */
#[AsEventListener]
final class FusionPanierListener
{
    public function __construct(private readonly Cart $cart)
    {
    }

    public function __invoke(LoginSuccessEvent $event): void
    {
        $client = $event->getUser();
        if (!$client instanceof Client || !$event->getRequest()->hasSession()) {
            return;
        }

        $this->cart->fusionnerDansCompte($client);
    }
}
