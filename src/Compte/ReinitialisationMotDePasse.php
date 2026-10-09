<?php

namespace App\Compte;

use App\Entity\Client;
use App\Repository\ClientRepository;
use Doctrine\DBAL\Connection;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * « Mot de passe oublié » : jeton à usage unique valable 1 heure.
 *
 * Le jeton envoyé par e-mail = sélecteur (20 caractères) + vérificateur (40 caractères).
 * Seule une empreinte HMAC du vérificateur est stockée en base.
 */
final class ReinitialisationMotDePasse
{
    private const DUREE_VALIDITE = '+1 hour';
    /** Pas plus d'un e-mail toutes les 5 minutes pour un même compte */
    private const DELAI_ENTRE_DEMANDES = 300;
    private const LONGUEUR_SELECTEUR = 20;
    private const LONGUEUR_JETON = 60;

    public function __construct(
        private readonly Connection $connection,
        private readonly ClientRepository $clients,
        private readonly CompteService $comptes,
        private readonly UrlGeneratorInterface $urlGenerator,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {
    }

    /**
     * Envoie le lien si un compte existe. Ne dit jamais si l'adresse est connue
     * (le contrôleur affiche le même message dans tous les cas).
     */
    public function demander(string $email): void
    {
        $maintenant = new \DateTimeImmutable();
        $this->connection->executeStatement('DELETE FROM client_reinitialisations WHERE date_expiration < ?', [$maintenant->format('Y-m-d H:i:s')]);

        $client = $this->clients->findOneBy(['email' => mb_strtolower(trim($email))]);
        if ($client === null) {
            return;
        }

        $derniere = $this->connection->fetchOne('SELECT MAX(date_demande) FROM client_reinitialisations WHERE client_id = ?', [$client->getId()]);
        if ($derniere && strtotime($derniere) > $maintenant->getTimestamp() - self::DELAI_ENTRE_DEMANDES) {
            return;
        }

        $selecteur = bin2hex(random_bytes(self::LONGUEUR_SELECTEUR / 2));
        $verificateur = bin2hex(random_bytes((self::LONGUEUR_JETON - self::LONGUEUR_SELECTEUR) / 2));

        $this->connection->executeStatement('DELETE FROM client_reinitialisations WHERE client_id = ?', [$client->getId()]);
        $this->connection->insert('client_reinitialisations', [
            'client_id' => $client->getId(),
            'selecteur' => $selecteur,
            'verificateur_hash' => $this->empreinte($verificateur),
            'date_demande' => $maintenant->format('Y-m-d H:i:s'),
            'date_expiration' => $maintenant->modify(self::DUREE_VALIDITE)->format('Y-m-d H:i:s'),
        ]);

        $this->comptes->envoyer($client, 'email.reinitialisation.sujet', 'emails/compte_reinitialisation.html.twig', [
            'url' => $this->urlGenerator->generate('app_compte_reinitialiser', ['jeton' => $selecteur . $verificateur], UrlGeneratorInterface::ABSOLUTE_URL),
        ]);
    }

    /**
     * @return Client|null le client du jeton, ou null si le jeton est inconnu, expiré ou falsifié
     */
    public function clientDuJeton(?string $jeton): ?Client
    {
        if ($jeton === null || strlen($jeton) !== self::LONGUEUR_JETON || !ctype_xdigit($jeton)) {
            return null;
        }

        $demande = $this->connection->fetchAssociative(
            'SELECT * FROM client_reinitialisations WHERE selecteur = ?',
            [substr($jeton, 0, self::LONGUEUR_SELECTEUR)]
        );

        if ($demande === false
            || strtotime($demande['date_expiration']) < time()
            || !hash_equals($demande['verificateur_hash'], $this->empreinte(substr($jeton, self::LONGUEUR_SELECTEUR)))) {
            return null;
        }

        return $this->clients->find($demande['client_id']);
    }

    /** Le lien ne peut servir qu'une fois. */
    public function terminer(Client $client): void
    {
        $this->connection->executeStatement('DELETE FROM client_reinitialisations WHERE client_id = ?', [$client->getId()]);
    }

    private function empreinte(string $verificateur): string
    {
        return hash_hmac('sha256', $verificateur, $this->secret);
    }
}
