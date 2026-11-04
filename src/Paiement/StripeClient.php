<?php

namespace App\Paiement;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Appels à l'API de Stripe (paiement par carte) et vérification des notifications (« webhooks »).
 *
 * Le paiement se fait sur la page sécurisée de Stripe (Checkout) : les numéros de carte ne passent
 * jamais par le site. Clés de test ou réelles selon .env.local (STRIPE_SECRET_KEY) : le code est le même.
 */
final class StripeClient
{
    /** Écart maximal accepté entre l'heure de la notification et maintenant (rejeu d'une vieille notification) */
    private const TOLERANCE_SIGNATURE = 300;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        #[Autowire('%env(STRIPE_SECRET_KEY)%')]
        private readonly string $cleSecrete,
        #[Autowire('%env(STRIPE_WEBHOOK_SECRET)%')]
        private readonly string $secretWebhook,
        #[Autowire('%env(STRIPE_API_URL)%')]
        private readonly string $urlApi,
    ) {
    }

    /** La carte n'est proposée que si une clé est configurée */
    public function actif(): bool
    {
        return $this->cleSecrete !== '';
    }

    /** Clés de test (sk_test_…) : liens du tableau de bord Stripe en mode test */
    public function modeTest(): bool
    {
        return str_starts_with($this->cleSecrete, 'sk_test_');
    }

    /**
     * Crée une session de paiement Stripe Checkout.
     *
     * @return array la session (id, url de la page de paiement, statut…)
     */
    public function creerSession(array $parametres): array
    {
        return $this->appeler('POST', '/v1/checkout/sessions', $parametres);
    }

    public function session(string $id): array
    {
        return $this->appeler('GET', '/v1/checkout/sessions/' . rawurlencode($id));
    }

    /** Ferme une page de paiement encore ouverte : elle ne peut plus être payée */
    public function expirerSession(string $id): array
    {
        return $this->appeler('POST', '/v1/checkout/sessions/' . rawurlencode($id) . '/expire');
    }

    /**
     * Vérifie qu'une notification vient bien de Stripe (signature HMAC de l'en-tête Stripe-Signature)
     * et renvoie l'événement décodé.
     *
     * @throws \UnexpectedValueException signature absente, invalide ou trop ancienne
     */
    public function evenementVerifie(string $contenu, string $signature): array
    {
        if ($this->secretWebhook === '') {
            throw new \UnexpectedValueException('STRIPE_WEBHOOK_SECRET n\'est pas configuré.');
        }

        $horodatage = null;
        $signatures = [];
        foreach (explode(',', $signature) as $element) {
            [$cle, $valeur] = array_pad(explode('=', trim($element), 2), 2, '');
            if ($cle === 't') {
                $horodatage = (int) $valeur;
            } elseif ($cle === 'v1') {
                $signatures[] = $valeur;
            }
        }
        if ($horodatage === null || $signatures === []) {
            throw new \UnexpectedValueException('En-tête Stripe-Signature incomplet.');
        }
        if (abs(time() - $horodatage) > self::TOLERANCE_SIGNATURE) {
            throw new \UnexpectedValueException('Notification trop ancienne.');
        }

        $attendue = hash_hmac('sha256', $horodatage . '.' . $contenu, $this->secretWebhook);
        foreach ($signatures as $recue) {
            if (hash_equals($attendue, $recue)) {
                return json_decode($contenu, true, 512, JSON_THROW_ON_ERROR);
            }
        }

        throw new \UnexpectedValueException('Signature invalide.');
    }

    private function appeler(string $methode, string $chemin, array $parametres = []): array
    {
        if (!$this->actif()) {
            throw new \LogicException('STRIPE_SECRET_KEY n\'est pas configuré.');
        }

        $options = ['auth_bearer' => $this->cleSecrete, 'timeout' => 20];
        if ($parametres !== []) {
            // format attendu par Stripe : line_items[0][price_data][currency]=eur…
            $options['body'] = http_build_query($parametres, '', '&', PHP_QUERY_RFC1738);
            $options['headers'] = ['Content-Type' => 'application/x-www-form-urlencoded'];
        }

        $reponse = $this->httpClient->request($methode, rtrim($this->urlApi, '/') . $chemin, $options);
        $donnees = $reponse->toArray(false);
        if ($reponse->getStatusCode() >= 400) {
            throw new \RuntimeException(sprintf('Stripe a refusé la requête (%d) : %s', $reponse->getStatusCode(), $donnees['error']['message'] ?? 'erreur inconnue'));
        }

        return $donnees;
    }
}
