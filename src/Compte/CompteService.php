<?php

namespace App\Compte;

use App\Entity\Client;
use App\I18n\Contenu;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\UriSigner;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Vérification de l'adresse e-mail, rattachement des commandes passées sans compte,
 * export et suppression des données (RGPD).
 */
final class CompteService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly EntityManagerInterface $entityManager,
        private readonly MailerInterface $mailer,
        private readonly UriSigner $uriSigner,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly LoggerInterface $logger,
        private readonly Contenu $contenu,
        private readonly TranslatorInterface $translator,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $expediteur,
    ) {
    }

    // ---------- Vérification de l'adresse e-mail ----------

    /**
     * Envoie un lien signé, valable 24 h. Il contient l'empreinte de l'adresse :
     * un changement d'e-mail rend donc les anciens liens inutilisables.
     */
    public function envoyerVerification(Client $client): bool
    {
        $url = $this->urlGenerator->generate('app_compte_verifier_email', [
            'id' => $client->getId(),
            'empreinte' => self::empreinte($client->getEmail()),
        ], UrlGeneratorInterface::ABSOLUTE_URL);

        return $this->envoyer($client, 'email.verification.sujet', 'emails/compte_verification.html.twig', [
            'url' => $this->uriSigner->sign($url, new \DateTimeImmutable('+24 hours')),
        ]);
    }

    /**
     * @return Client|null le client concerné, ou null si le lien est invalide, modifié ou expiré
     */
    public function clientDuLien(Request $request): ?Client
    {
        if (!$this->uriSigner->checkRequest($request)) {
            return null;
        }

        $client = $this->entityManager->find(Client::class, $request->query->getInt('id'));
        if ($client === null || !hash_equals(self::empreinte($client->getEmail()), $request->query->getString('empreinte'))) {
            return null;
        }

        return $client;
    }

    /**
     * @return int nombre de commandes passées sans compte qui ont été rattachées
     */
    public function marquerVerifie(Client $client): int
    {
        $client->setEmailVerifie(true);
        $this->entityManager->flush();

        return $this->rattacherCommandes($client);
    }

    // ---------- Commandes passées sans compte ----------

    /**
     * Rattache au compte les commandes passées sans compte avec la même adresse.
     * Uniquement si l'adresse est vérifiée : sinon n'importe qui pourrait créer un compte
     * avec l'e-mail d'un autre pour voir ses commandes.
     */
    public function rattacherCommandes(Client $client): int
    {
        if (!$client->isEmailVerifie()) {
            return 0;
        }

        return (int) $this->connection->executeStatement(
            'UPDATE commandes SET client_id = ? WHERE client_id IS NULL AND LOWER(email) = ?',
            [$client->getId(), $client->getEmail()]
        );
    }

    public function nbCommandesSansCompte(?string $email): int
    {
        if (!$email) {
            return 0;
        }

        return (int) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM commandes WHERE client_id IS NULL AND LOWER(email) = ?',
            [mb_strtolower($email)]
        );
    }

    // ---------- RGPD ----------

    /**
     * Toutes les données du client, pour le téléchargement « Mes données ».
     */
    public function exporter(Client $client): array
    {
        $commandes = $this->connection->fetchAllAssociative(
            'SELECT * FROM commandes WHERE client_id = ? ORDER BY date_commande',
            [$client->getId()]
        );
        foreach ($commandes as &$commande) {
            $commande['lignes'] = $this->connection->fetchAllAssociative(
                'SELECT nom_piece, prix_unitaire, quantite FROM commande_lignes WHERE commande_id = ? ORDER BY id',
                [$commande['id']]
            );
            unset($commande['id'], $commande['client_id']);
        }
        unset($commande);

        return [
            'date_export' => (new \DateTimeImmutable())->format(\DATE_ATOM),
            'compte' => [
                'email' => $client->getEmail(),
                'email_verifie' => $client->isEmailVerifie(),
                'prenom' => $client->getPrenom(),
                'nom' => $client->getNom(),
                'telephone' => $client->getTelephone(),
                'adresse' => $client->getAdresse(),
                'code_postal' => $client->getCodePostal(),
                'ville' => $client->getVille(),
                'pays' => $client->getPays(),
                'langue' => $client->getLangue(),
                'date_creation' => $client->getDateCreation()->format(\DATE_ATOM),
                'derniere_connexion' => $client->getDerniereConnexion()?->format(\DATE_ATOM),
            ],
            'commandes' => $commandes,
            'panier' => $this->connection->fetchAllAssociative(
                'SELECT p.nom, cp.quantite, cp.date_ajout FROM client_panier cp INNER JOIN pieces_detachees p ON p.id = cp.piece_id WHERE cp.client_id = ?',
                [$client->getId()]
            ),
            'alertes_stock' => $this->connection->fetchAllAssociative(
                'SELECT p.nom AS piece, a.langue, a.date_demande, a.date_envoi FROM alertes_stock a INNER JOIN pieces_detachees p ON p.id = a.piece_id WHERE a.email = ? ORDER BY a.date_demande',
                [$client->getEmail()]
            ),
        ];
    }

    /**
     * Supprime le compte. Les commandes sont conservées (obligations comptables)
     * mais ne sont plus rattachées à un compte.
     */
    public function supprimer(Client $client): void
    {
        $this->connection->executeStatement('UPDATE commandes SET client_id = NULL WHERE client_id = ?', [$client->getId()]);
        $this->connection->executeStatement('DELETE FROM alertes_stock WHERE email = ?', [$client->getEmail()]);
        // le panier sauvegardé et les demandes de réinitialisation suivent (ON DELETE CASCADE)
        $this->entityManager->remove($client);
        $this->entityManager->flush();
    }

    // ---------- E-mails ----------

    /**
     * E-mail au client, dans la langue de la page qui l'a déclenché.
     *
     * @param string $sujet clé de traduction du sujet
     */
    public function envoyer(Client $client, string $sujet, string $template, array $contexte = []): bool
    {
        $langue = $this->contenu->langue();

        try {
            $this->mailer->send((new TemplatedEmail())
                ->from(new Address($this->expediteur, 'Bultaco Club France'))
                ->to(new Address($client->getEmail(), $client->getNomComplet()))
                ->subject($this->translator->trans($sujet, [], null, $langue))
                ->htmlTemplate($template)
                ->locale($langue)
                ->context($contexte + ['client' => $client]));

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Envoi d\'un e-mail de compte impossible', ['sujet' => $sujet, 'exception' => $e]);

            return false;
        }
    }

    private static function empreinte(?string $email): string
    {
        return hash('sha256', mb_strtolower((string) $email));
    }
}
