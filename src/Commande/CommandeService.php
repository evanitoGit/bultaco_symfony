<?php

namespace App\Commande;

use App\Cart\Cart;
use App\Entity\Client;
use App\I18n\Contenu;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

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
        private readonly Contenu $contenu,
        private readonly TranslatorInterface $translator,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $expediteur,
        #[Autowire('%env(CLUB_EMAIL)%')]
        private readonly string $emailClub,
    ) {
    }

    /**
     * @param array       $client données validées du formulaire de commande
     * @param Client|null $compte compte du client connecté (null = commande sans compte)
     *
     * @return array la commande enregistrée, avec ses lignes
     *
     * @throws StockInsuffisantException si une pièce a été commandée par quelqu'un d'autre entre-temps
     */
    public function creer(array $client, ?Client $compte = null): array
    {
        // Prix relus en base au moment de la validation, jamais ceux du navigateur
        $lignes = $this->cart->lignes();
        if ($lignes === []) {
            throw new \LogicException('Le panier est vide.');
        }

        $total = array_sum(array_column($lignes, 'sous_total'));
        $reference = 'BCF-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(3))); // ex. BCF-261006-4F2A9C

        $id = $this->connection->transactional(function (Connection $connection) use ($client, $compte, $lignes, $total, $reference): int {
            $this->retirerDuStock($connection, $lignes);

            $connection->insert('commandes', [
                'client_id' => $compte?->getId(),
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
                'langue' => $this->contenu->langue(), // e-mails envoyés dans la langue du client
                'total' => self::euros($total),
            ]);
            $id = (int) $connection->lastInsertId();

            foreach ($lignes as $ligne) {
                $connection->insert('commande_lignes', [
                    'commande_id' => $id,
                    'piece_id' => $ligne['id'],
                    'nom_piece' => $ligne['nom_fr'],
                    'prix_unitaire' => self::euros($ligne['prix_unitaire']),
                    'quantite' => $ligne['quantite'],
                ]);
            }

            // Première commande : l'adresse de livraison devient l'adresse du compte
            if ($compte !== null && !$compte->hasAdresse()) {
                $connection->update('clients', [
                    'telephone' => $client['telephone'] ?: $compte->getTelephone(),
                    'adresse' => $client['adresse'],
                    'code_postal' => $client['code_postal'],
                    'ville' => $client['ville'],
                    'pays' => $client['pays'],
                ], ['id' => $compte->getId()]);
            }

            return $id;
        });

        $this->cart->clear();
        $commande = $this->trouver($id);
        $this->envoyerEmails($commande);

        return $commande;
    }

    /**
     * Change le statut d'une commande (administration).
     * Annuler une commande remet ses pièces en stock, une seule fois ; la réactiver les retire à nouveau.
     */
    public function changerStatut(int $id, string $statut): void
    {
        $this->connection->transactional(function (Connection $connection) use ($id, $statut): void {
            $restitue = (bool) $connection->fetchOne('SELECT stock_restitue FROM commandes WHERE id = ? FOR UPDATE', [$id]);
            $annulee = $statut === 'annulee';

            if ($annulee !== $restitue) {
                $lignes = $connection->fetchAllAssociative(
                    'SELECT piece_id, quantite FROM commande_lignes WHERE commande_id = ? AND piece_id IS NOT NULL',
                    [$id]
                );
                foreach ($lignes as $ligne) {
                    // GREATEST : le stock ne descend jamais sous zéro (colonne non signée)
                    $connection->executeStatement(
                        $annulee
                            ? 'UPDATE pieces_detachees SET stock = stock + ? WHERE id = ? AND stock IS NOT NULL'
                            : 'UPDATE pieces_detachees SET stock = GREATEST(stock, ?) - ? WHERE id = ? AND stock IS NOT NULL',
                        $annulee
                            ? [$ligne['quantite'], $ligne['piece_id']]
                            : [$ligne['quantite'], $ligne['quantite'], $ligne['piece_id']]
                    );
                }
            }

            $connection->update('commandes', ['statut' => $statut, 'stock_restitue' => (int) $annulee], ['id' => $id]);
        });
    }

    /**
     * Décrémente le stock des pièces commandées. Les lignes sont verrouillées (FOR UPDATE) :
     * deux commandes simultanées de la dernière pièce ne peuvent pas passer toutes les deux.
     */
    private function retirerDuStock(Connection $connection, array $lignes): void
    {
        foreach ($lignes as $ligne) {
            $stock = $connection->fetchOne('SELECT stock FROM pieces_detachees WHERE id = ? FOR UPDATE', [$ligne['id']]);
            if ($stock === null) {
                continue; // stock non suivi
            }
            if ($stock === false || (int) $stock < $ligne['quantite']) {
                throw new StockInsuffisantException($ligne['nom'], max(0, (int) $stock));
            }
            $connection->executeStatement('UPDATE pieces_detachees SET stock = stock - ? WHERE id = ?', [$ligne['quantite'], $ligne['id']]);
        }
    }

    public function trouver(int $id): ?array
    {
        $commande = $this->connection->fetchAssociative('SELECT * FROM commandes WHERE id = ?', [$id]);
        if ($commande === false) {
            return null;
        }

        // nom_piece : nom en français au moment de l'achat ; nom_piece_en/_es : traductions actuelles de la pièce
        $commande['lignes'] = $this->connection->fetchAllAssociative(
            'SELECT l.*, p.nom_en AS nom_piece_en, p.nom_es AS nom_piece_es
             FROM commande_lignes l LEFT JOIN pieces_detachees p ON p.id = l.piece_id
             WHERE l.commande_id = ? ORDER BY l.id',
            [$id]
        );

        return $commande;
    }

    /**
     * Commandes d'un client, de la plus récente à la plus ancienne.
     */
    public function commandesDuClient(int $clientId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT c.*, (SELECT SUM(l.quantite) FROM commande_lignes l WHERE l.commande_id = c.id) AS nb_articles
             FROM commandes c WHERE c.client_id = ? ORDER BY c.date_commande DESC, c.id DESC',
            [$clientId]
        );
    }

    /**
     * Une commande du client (null si elle n'existe pas ou appartient à quelqu'un d'autre).
     */
    public function trouverPourClient(string $reference, int $clientId): ?array
    {
        $id = $this->connection->fetchOne(
            'SELECT id FROM commandes WHERE reference = ? AND client_id = ?',
            [$reference, $clientId]
        );

        return $id === false ? null : $this->trouver((int) $id);
    }

    /** @return array<string, string> statut en base => libellé */
    public static function libellesStatuts(): array
    {
        return array_flip(self::STATUTS);
    }

    private function envoyerEmails(array $commande): void
    {
        $from = new Address($this->expediteur, 'Bultaco Club France');
        $langue = $commande['langue'] ?? 'fr';

        try {
            // Confirmation au client, dans sa langue
            $this->mailer->send((new TemplatedEmail())
                ->from($from)
                ->to(new Address($commande['email'], $commande['prenom'] . ' ' . $commande['nom']))
                ->subject($this->translator->trans('email.commande.sujet', ['reference' => $commande['reference']], null, $langue))
                ->htmlTemplate('emails/commande_client.html.twig')
                ->locale($langue)
                ->context(['commande' => $commande]));

            // Notification au club (« Répondre » écrit directement au client)
            $this->mailer->send((new TemplatedEmail())
                ->from($from)
                ->to($this->emailClub)
                ->replyTo($commande['email'])
                ->subject(sprintf('Nouvelle commande %s (%s €)', $commande['reference'], number_format((float) $commande['total'], 2, ',', ' ')))
                ->htmlTemplate('emails/commande_club.html.twig')
                ->locale('fr')
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
