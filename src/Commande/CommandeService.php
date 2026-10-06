<?php

namespace App\Commande;

use App\Cart\Cart;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;

/**
 * Transforme le panier en commande enregistrée et prévient le client et le club.
 */
final class CommandeService
{
    public const STATUTS = [
        'En attente de paiement' => 'en_attente',
        'Payée' => 'payee',
        'Expédiée' => 'expediee',
        'Annulée' => 'annulee',
    ];

    public const PAIEMENTS = [
        'Virement bancaire' => 'virement',
        'Chèque' => 'cheque',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly Cart $cart,
        private readonly MailerInterface $mailer,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $expediteur,
        #[Autowire('%env(CLUB_EMAIL)%')]
        private readonly string $emailClub,
    ) {
    }

    /**
     * @param array $client données validées du formulaire de commande
     *
     * @return array la commande enregistrée, avec ses lignes
     */
    public function creer(array $client): array
    {
        // Prix relus en base au moment de la validation, jamais ceux du navigateur
        $lignes = $this->cart->lignes();
        if ($lignes === []) {
            throw new \LogicException('Le panier est vide.');
        }

        $total = array_sum(array_column($lignes, 'sous_total'));
        $reference = 'BCF-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))); // ex. BCF-261006-4F2A9C

        $id = $this->connection->transactional(function (Connection $connection) use ($client, $lignes, $total, $reference): int {
            $connection->insert('commandes', [
                'reference' => $reference,
                'prenom' => $client['prenom'],
                'nom' => $client['nom'],
                'email' => $client['email'],
                'telephone' => $client['telephone'],
                'adresse' => $client['adresse'],
                'code_postal' => $client['code_postal'],
                'ville' => $client['ville'],
                'pays' => $client['pays'],
                'mode_paiement' => $client['mode_paiement'],
                'commentaire' => $client['commentaire'],
                'total' => self::euros($total),
            ]);
            $id = (int) $connection->lastInsertId();

            foreach ($lignes as $ligne) {
                $connection->insert('commande_lignes', [
                    'commande_id' => $id,
                    'piece_id' => $ligne['id'],
                    'nom_piece' => $ligne['nom'],
                    'prix_unitaire' => self::euros($ligne['prix_unitaire']),
                    'quantite' => $ligne['quantite'],
                ]);
            }

            return $id;
        });

        $this->cart->clear();
        $commande = $this->trouver($id);
        $this->envoyerEmails($commande);

        return $commande;
    }

    public function trouver(int $id): ?array
    {
        $commande = $this->connection->fetchAssociative('SELECT * FROM commandes WHERE id = ?', [$id]);
        if ($commande === false) {
            return null;
        }

        $commande['lignes'] = $this->connection->fetchAllAssociative(
            'SELECT * FROM commande_lignes WHERE commande_id = ? ORDER BY id',
            [$id]
        );

        return $commande;
    }

    private function envoyerEmails(array $commande): void
    {
        $from = new Address($this->expediteur, 'Bultaco Club France');

        try {
            // Confirmation au client
            $this->mailer->send((new TemplatedEmail())
                ->from($from)
                ->to(new Address($commande['email'], $commande['prenom'] . ' ' . $commande['nom']))
                ->subject('Votre commande ' . $commande['reference'])
                ->htmlTemplate('emails/commande_client.html.twig')
                ->context(['commande' => $commande]));

            // Notification au club (« Répondre » écrit directement au client)
            $this->mailer->send((new TemplatedEmail())
                ->from($from)
                ->to($this->emailClub)
                ->replyTo($commande['email'])
                ->subject(sprintf('Nouvelle commande %s (%s €)', $commande['reference'], number_format((float) $commande['total'], 2, ',', ' ')))
                ->htmlTemplate('emails/commande_club.html.twig')
                ->context(['commande' => $commande]));
        } catch (TransportExceptionInterface $e) {
            // La commande est enregistrée même si l'e-mail échoue : on le note dans les logs
            $this->logger->error('Envoi des e-mails de commande impossible', ['reference' => $commande['reference'], 'exception' => $e]);
        }
    }

    /** 8990 centimes -> "89.90" (format attendu par une colonne DECIMAL) */
    private static function euros(int $centimes): string
    {
        return number_format($centimes / 100, 2, '.', '');
    }
}
