<?php

namespace App\Boutique;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Prévenez-moi quand elle revient » : un visiteur laisse son e-mail sur une pièce en rupture,
 * il reçoit un e-mail (dans sa langue) dès que la pièce est de nouveau disponible.
 * Chaque alerte ne sert qu'une fois.
 */
final class AlerteStock
{
    /** Les alertes envoyées sont effacées au bout de 6 mois */
    private const CONSERVATION = '-6 months';

    public function __construct(
        private readonly Connection $connection,
        private readonly Catalogue $catalogue,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $expediteur,
    ) {
    }

    /**
     * Inscrit l'adresse (ou réactive une alerte déjà envoyée) et envoie la confirmation
     * avec le lien d'annulation. Rien n'est fait si une alerte attend déjà.
     *
     * @return bool false si la pièce est disponible (pas besoin d'alerte)
     */
    public function inscrire(array $piece, string $email, string $langue): bool
    {
        if (self::disponible($piece)) {
            return false;
        }

        $this->connection->executeStatement('DELETE FROM alertes_stock WHERE date_envoi < ?', [(new \DateTimeImmutable(self::CONSERVATION))->format('Y-m-d H:i:s')]);

        $email = mb_strtolower(trim($email));
        $existante = $this->connection->fetchAssociative('SELECT * FROM alertes_stock WHERE piece_id = ? AND email = ?', [$piece['id'], $email]);
        if ($existante && $existante['date_envoi'] === null) {
            return true; // déjà inscrit
        }

        $jeton = bin2hex(random_bytes(16));
        if ($existante) {
            $this->connection->update('alertes_stock', ['langue' => $langue, 'jeton' => $jeton, 'date_demande' => date('Y-m-d H:i:s'), 'date_envoi' => null], ['id' => $existante['id']]);
        } else {
            $this->connection->insert('alertes_stock', ['piece_id' => $piece['id'], 'email' => $email, 'langue' => $langue, 'jeton' => $jeton]);
        }

        $this->envoyer($email, $langue, 'email.alerte.confirmation_sujet', 'emails/alerte_confirmation.html.twig', $piece, $jeton);

        return true;
    }

    /**
     * Pièces dont le stock a pu remonter (administration, commande annulée) :
     * les alertes en attente des pièces de nouveau disponibles sont envoyées.
     *
     * @param list<int> $pieceIds
     *
     * @return int nombre d'e-mails envoyés
     */
    public function envoyerDisponibles(array $pieceIds): int
    {
        if ($pieceIds === []) {
            return 0;
        }

        $alertes = $this->connection->fetchAllAssociative(
            'SELECT a.* FROM alertes_stock a INNER JOIN pieces_detachees p ON p.id = a.piece_id
             WHERE a.piece_id IN (?) AND a.date_envoi IS NULL AND (p.stock IS NULL OR p.stock > 0)
             ORDER BY a.date_demande ASC',
            [array_values(array_unique($pieceIds))],
            [ArrayParameterType::INTEGER]
        );

        $envoyes = 0;
        foreach ($alertes as $alerte) {
            $piece = $this->catalogue->piece((int) $alerte['piece_id']);
            if ($piece && $this->envoyer($alerte['email'], $alerte['langue'], 'email.alerte.retour_sujet', 'emails/alerte_retour.html.twig', $piece, $alerte['jeton'])) {
                $this->connection->update('alertes_stock', ['date_envoi' => date('Y-m-d H:i:s')], ['id' => $alerte['id']]);
                ++$envoyes;
            }
        }

        return $envoyes;
    }

    public function alerte(string $jeton): ?array
    {
        if (!preg_match('/^[0-9a-f]{32}$/', $jeton)) {
            return null;
        }

        return $this->connection->fetchAssociative('SELECT * FROM alertes_stock WHERE jeton = ?', [$jeton]) ?: null;
    }

    public function annuler(array $alerte): void
    {
        $this->connection->delete('alertes_stock', ['id' => $alerte['id']]);
    }

    public function enAttente(int $pieceId, string $email): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT 1 FROM alertes_stock WHERE piece_id = ? AND email = ? AND date_envoi IS NULL',
            [$pieceId, mb_strtolower($email)]
        );
    }

    public static function disponible(array $piece): bool
    {
        return $piece['stock'] === null || (int) $piece['stock'] > 0;
    }

    /**
     * E-mail dans la langue du visiteur : nom de la pièce et adresses traduits.
     */
    private function envoyer(string $email, string $langue, string $sujet, string $template, array $piece, string $jeton): bool
    {
        $piece = $this->catalogue->pieceTraduite($piece, $langue);

        try {
            $this->mailer->send((new TemplatedEmail())
                ->from(new Address($this->expediteur, 'Bultaco Club France'))
                ->to($email)
                ->subject($this->translator->trans($sujet, ['nom' => $piece['nom']], null, $langue))
                ->htmlTemplate($template)
                ->locale($langue)
                ->context([
                    'piece' => $piece,
                    'url_piece' => $this->catalogue->url($piece, $langue, true),
                    'url_annuler' => $this->urlGenerator->generate('app_alerte_annuler', ['jeton' => $jeton, '_locale' => $langue], UrlGeneratorInterface::ABSOLUTE_URL),
                ]));

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Envoi d\'une alerte de stock impossible', ['piece' => $piece['id'], 'exception' => $e]);

            return false;
        }
    }
}
