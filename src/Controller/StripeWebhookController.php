<?php

namespace App\Controller;

use App\Paiement\PaiementCarte;
use App\Paiement\StripeClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Notifications envoyées par Stripe (paiement réussi, session expirée…).
 * Adresse à déclarer dans le tableau de bord Stripe : https://<site>/paiement/webhook
 *
 * Elle confirme les paiements même si le client ferme son navigateur avant de revenir sur le site.
 */
final class StripeWebhookController
{
    #[Route('/paiement/webhook', name: 'app_paiement_webhook', methods: ['POST'])]
    public function __invoke(Request $request, StripeClient $stripe, PaiementCarte $carte, LoggerInterface $logger): Response
    {
        try {
            $evenement = $stripe->evenementVerifie($request->getContent(), (string) $request->headers->get('Stripe-Signature'));
        } catch (\UnexpectedValueException|\JsonException $e) {
            $logger->warning('Notification Stripe refusée', ['raison' => $e->getMessage()]);

            return new Response('Signature invalide', Response::HTTP_BAD_REQUEST);
        }

        // une erreur ici renvoie un code 500 : Stripe renverra la notification plus tard
        $carte->traiterEvenement($evenement);

        return new Response('', Response::HTTP_NO_CONTENT);
    }
}
