<?php

namespace App\Paiement;

use App\Cart\Cart;
use App\Commande\CommandeService;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Paiement d'une commande par carte bancaire avec Stripe Checkout.
 * Les moyens de paiement proposés (carte, Apple Pay, Google Pay…) se règlent dans le tableau de bord Stripe :
 * Paramètres > Moyens de paiement.
 *
 * 1. demarrer()  : la commande est enregistrée « en attente », le client part payer sur la page de Stripe ;
 * 2. confirmer() : Stripe confirme le paiement (retour du client ET notification webhook, le premier arrivé
 *                  fait le travail) -> commande « payée », e-mails de confirmation envoyés une seule fois ;
 * 3. expirer()   : le client n'a pas payé avant l'expiration de la session -> commande annulée, pièces remises en stock.
 */
final class PaiementCarte
{
    /** Durée de la page de paiement Stripe (30 minutes minimum imposées par Stripe) */
    private const DUREE_SESSION = 31 * 60;

    public function __construct(
        private readonly StripeClient $stripe,
        private readonly CommandeService $commandes,
        private readonly Connection $connection,
        private readonly Cart $cart,
        private readonly UrlGeneratorInterface $urls,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function actif(): bool
    {
        return $this->stripe->actif();
    }

    /** @return array<string, string> modes de paiement proposés au client */
    public function modesDisponibles(): array
    {
        return $this->actif() ? CommandeService::PAIEMENT_CARTE + CommandeService::PAIEMENTS : CommandeService::PAIEMENTS;
    }

    /**
     * Ouvre une session de paiement Stripe pour la commande.
     *
     * @return string adresse de la page de paiement Stripe vers laquelle rediriger le client
     */
    public function demarrer(array $commande): string
    {
        $langue = $commande['langue'] ?? 'fr';
        $lignes = [];
        foreach ($commande['lignes'] as $i => $ligne) {
            $nom = ($langue !== 'fr' ? ($ligne['nom_piece_' . $langue] ?? null) : null) ?: $ligne['nom_piece'];
            $lignes[$i] = [
                'quantity' => (int) $ligne['quantite'],
                'price_data' => [
                    'currency' => 'eur',
                    'unit_amount' => self::centimes($ligne['prix_unitaire']),
                    'product_data' => ['name' => $nom],
                ],
            ];
        }

        $url = fn (string $route) => $this->urls->generate($route, ['reference' => $commande['reference'], '_locale' => $langue], UrlGeneratorInterface::ABSOLUTE_URL);

        $session = $this->stripe->creerSession([
            'mode' => 'payment',
            'line_items' => $lignes,
            'customer_email' => $commande['email'],
            'client_reference_id' => $commande['reference'],
            'metadata' => ['commande_id' => $commande['id'], 'reference' => $commande['reference']],
            'payment_intent_data' => [
                'description' => 'Commande ' . $commande['reference'],
                'metadata' => ['commande_id' => $commande['id'], 'reference' => $commande['reference']],
            ],
            'locale' => in_array($langue, ['fr', 'en', 'es'], true) ? $langue : 'auto',
            'success_url' => $url('app_commande_paiement_retour'),
            'cancel_url' => $url('app_commande_paiement_annule'),
            'expires_at' => time() + self::DUREE_SESSION,
        ]);

        // une seule session « en cours » par commande : celle qui pourra l'annuler en expirant
        $this->connection->update('commandes', ['stripe_session_id' => $session['id']], ['id' => $commande['id']]);

        // nouvelle tentative : l'ancienne page de paiement (restée ouverte dans un autre onglet ?) est fermée,
        // après l'enregistrement de la nouvelle pour que son expiration n'annule pas la commande
        if ($commande['stripe_session_id']) {
            $this->fermerSession($commande['stripe_session_id']);
        }

        return $session['url'];
    }

    /**
     * Retour du client depuis Stripe : vérifie auprès de Stripe (jamais sur la seule foi de l'adresse)
     * que la commande est payée.
     */
    public function verifier(array $commande): bool
    {
        if ($commande['date_paiement'] !== null) {
            return true;
        }
        if (!$commande['stripe_session_id']) {
            return false;
        }

        return $this->confirmer($this->stripe->session($commande['stripe_session_id']));
    }

    /**
     * Notification envoyée par Stripe (signature déjà vérifiée).
     */
    public function traiterEvenement(array $evenement): void
    {
        $session = $evenement['data']['object'] ?? [];

        match ($evenement['type'] ?? '') {
            'checkout.session.completed', 'checkout.session.async_payment_succeeded' => $this->confirmer($session),
            'checkout.session.expired', 'checkout.session.async_payment_failed' => $this->expirer($session),
            default => null, // autres événements : ignorés
        };
    }

    /**
     * Le client renonce à payer par carte : la commande est annulée (pièces remises en stock)
     * et son panier lui est rendu pour qu'il choisisse un autre mode de paiement.
     */
    public function abandonner(array $commande): void
    {
        if ($commande['statut'] !== 'en_attente' || $commande['date_paiement'] !== null) {
            return;
        }

        if ($commande['stripe_session_id']) {
            $this->fermerSession($commande['stripe_session_id']);
        }
        $this->commandes->changerStatut((int) $commande['id'], 'annulee');
        foreach ($commande['lignes'] as $ligne) {
            if ($ligne['piece_id'] !== null) {
                $this->cart->add((int) $ligne['piece_id'], (int) $ligne['quantite']);
            }
        }
    }

    /**
     * @return bool la commande est payée
     */
    private function confirmer(array $session): bool
    {
        if (!in_array($session['payment_status'] ?? '', ['paid', 'no_payment_required'], true)) {
            return false; // paiement pas encore abouti
        }

        $commande = $this->commandeDeLaSession($session);
        if ($commande === null) {
            return false;
        }
        if ($commande['date_paiement'] !== null) {
            return true; // déjà confirmée (retour du client puis notification, ou l'inverse)
        }

        if (($session['amount_total'] ?? null) !== self::centimes($commande['total'])) {
            $this->logger->critical('Montant payé par carte différent du total de la commande', [
                'reference' => $commande['reference'], 'paye' => $session['amount_total'] ?? null, 'total' => $commande['total'],
            ]);
        }

        // « date_paiement IS NULL » : une seule des deux confirmations simultanées passe
        $confirmee = $this->connection->executeStatement(
            'UPDATE commandes SET date_paiement = NOW(), stripe_paiement_id = ?, stripe_session_id = ? WHERE id = ? AND date_paiement IS NULL',
            [is_array($session['payment_intent'] ?? null) ? $session['payment_intent']['id'] : ($session['payment_intent'] ?? null), $session['id'], $commande['id']]
        );
        if ($confirmee === 0) {
            return true;
        }

        if ($commande['statut'] !== 'en_attente') {
            // payée après avoir été annulée : le club doit vérifier le stock (ou rembourser)
            $this->logger->critical('Paiement par carte reçu pour une commande non en attente', ['reference' => $commande['reference'], 'statut' => $commande['statut']]);
        }
        if (in_array($commande['statut'], ['en_attente', 'annulee'], true)) {
            $this->commandes->changerStatut((int) $commande['id'], 'payee');
        }

        $this->commandes->envoyerEmails($this->commandes->trouver((int) $commande['id']));

        return true;
    }

    private function expirer(array $session): void
    {
        $commande = $this->commandeDeLaSession($session);

        // seulement la session en cours : une ancienne session (paiement relancé) n'annule rien
        if ($commande !== null && $commande['statut'] === 'en_attente' && $commande['date_paiement'] === null
            && $commande['stripe_session_id'] === ($session['id'] ?? null)) {
            $this->commandes->changerStatut((int) $commande['id'], 'annulee');
        }
    }

    private function fermerSession(string $id): void
    {
        try {
            $this->stripe->expirerSession($id);
        } catch (\Throwable $e) {
            // déjà expirée ou payée : rien à fermer
            $this->logger->info('Session Stripe non fermée', ['session' => $id, 'raison' => $e->getMessage()]);
        }
    }

    private function commandeDeLaSession(array $session): ?array
    {
        $id = (int) ($session['metadata']['commande_id'] ?? 0);
        $commande = $id > 0 ? $this->commandes->trouver($id) : null;

        // la référence doit correspondre : protège d'une session étrangère portant un identifiant au hasard
        if ($commande === null || $commande['reference'] !== ($session['client_reference_id'] ?? null) || $commande['mode_paiement'] !== 'carte') {
            $this->logger->warning('Session Stripe sans commande correspondante', ['session' => $session['id'] ?? null]);

            return null;
        }

        return $commande;
    }

    /** "89.90" -> 8990 */
    private static function centimes(string|float $euros): int
    {
        return (int) round((float) $euros * 100);
    }
}
