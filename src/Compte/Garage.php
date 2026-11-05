<?php

namespace App\Compte;

use App\Boutique\Catalogue;
use App\Boutique\Fonctionnalites;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Mon garage » : les motos qu'un client enregistre dans son compte.
 * La boutique les lui propose en un clic, et il est prévenu par e-mail quand le club
 * ajoute une pièce compatible avec l'une d'elles.
 */
final class Garage
{
    /** Nombre maximal de motos par client */
    public const MAX = 10;

    public const ANNEE_MIN = 1958;

    public function __construct(
        private readonly Connection $connection,
        private readonly Catalogue $catalogue,
        private readonly Fonctionnalites $fonctionnalites,
        private readonly MailerInterface $mailer,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $expediteur,
    ) {
    }

    /** Motos du client, avec le nom du modèle */
    public function motos(int $clientId): array
    {
        return $this->connection->fetchAllAssociative(
            'SELECT cm.*, m.nom, m.cylindree,
                    (SELECT COUNT(*) FROM piece_modeles pm WHERE pm.modele_id = cm.modele_id) AS nb_pieces
             FROM client_motos cm INNER JOIN modeles_moto m ON m.id = cm.modele_id
             WHERE cm.client_id = ? ORDER BY cm.date_ajout ASC, cm.id ASC',
            [$clientId]
        );
    }

    /** Identifiants des modèles du garage (sans doublon), dans l'ordre d'ajout */
    public function modeles(int $clientId): array
    {
        return array_values(array_unique(array_map('intval', array_column($this->motos($clientId), 'modele_id'))));
    }

    /**
     * @return bool false si le garage est plein ou le modèle inconnu
     */
    public function ajouter(int $clientId, int $modeleId, ?int $annee, ?string $surnom): bool
    {
        if (count($this->motos($clientId)) >= self::MAX || $this->catalogue->modeleMoto($modeleId) === null) {
            return false;
        }
        $this->connection->insert('client_motos', [
            'client_id' => $clientId,
            'modele_id' => $modeleId,
            'annee' => $annee,
            'surnom' => $surnom !== null && trim($surnom) !== '' ? mb_substr(trim($surnom), 0, 60) : null,
        ]);

        return true;
    }

    public function retirer(int $clientId, int $motoId): void
    {
        $this->connection->delete('client_motos', ['id' => $motoId, 'client_id' => $clientId]);
    }

    public function alertes(int $clientId, int $motoId, bool $actives): void
    {
        $this->connection->update('client_motos', ['alertes' => (int) $actives], ['id' => $motoId, 'client_id' => $clientId]);
    }

    /** Pour l'export « Télécharger mes données » */
    public function exporter(int $clientId): array
    {
        return array_map(fn (array $moto) => [
            'modele' => $moto['nom'],
            'annee' => $moto['annee'],
            'surnom' => $moto['surnom'],
            'alertes_nouvelles_pieces' => (bool) $moto['alertes'],
            'date_ajout' => $moto['date_ajout'],
        ], $this->motos($clientId));
    }

    /**
     * Pièce ajoutée ou modifiée dans l'administration : les clients dont une moto du garage est
     * compatible sont prévenus, une seule fois par pièce. Seulement quand « Mon garage » est ouvert à tous.
     * Les pièces « universelles » ne déclenchent pas d'e-mail (elles iraient à tout le monde).
     *
     * @return int e-mails envoyés
     */
    public function prevenirNouvellePiece(int $pieceId): int
    {
        if (!$this->fonctionnalites->publique('mon_garage')) {
            return 0;
        }
        $piece = $this->catalogue->piece($pieceId);
        if ($piece === null || $piece['universelle'] || ($piece['stock'] !== null && (int) $piece['stock'] <= 0)) {
            return 0;
        }
        $modeles = array_map('intval', array_column($piece['modeles'], 'id'));
        if ($modeles === []) {
            return 0;
        }

        // une ligne par client : la première de ses motos compatibles (alertes activées), pas encore prévenu pour cette pièce
        $destinataires = $this->connection->fetchAllAssociative(
            'SELECT c.id, c.email, c.prenom, c.langue, MIN(m.nom) AS moto
             FROM client_motos cm
             INNER JOIN clients c ON c.id = cm.client_id
             INNER JOIN modeles_moto m ON m.id = cm.modele_id
             WHERE cm.modele_id IN (?) AND cm.alertes = 1 AND c.email_verifie = 1
               AND NOT EXISTS (SELECT 1 FROM garage_notifications n WHERE n.client_id = c.id AND n.piece_id = ?)
             GROUP BY c.id, c.email, c.prenom, c.langue',
            [$modeles, $pieceId],
            [ArrayParameterType::INTEGER, ParameterType::INTEGER]
        );

        $envoyes = 0;
        foreach ($destinataires as $client) {
            // noté avant l'envoi : un double enregistrement de la pièce ne renvoie rien
            $this->connection->executeStatement('INSERT IGNORE INTO garage_notifications (client_id, piece_id) VALUES (?, ?)', [$client['id'], $pieceId]);
            if ($this->envoyer($client, $piece)) {
                ++$envoyes;
            }
        }

        return $envoyes;
    }

    private function envoyer(array $client, array $piece): bool
    {
        $langue = in_array($client['langue'], ['fr', 'en', 'es'], true) ? $client['langue'] : 'fr';
        $piece = $this->catalogue->pieceTraduite($piece, $langue);

        try {
            $this->mailer->send((new TemplatedEmail())
                ->from(new Address($this->expediteur, 'Bultaco Club France'))
                ->to(new Address($client['email'], (string) $client['prenom']))
                ->subject($this->translator->trans('email.garage.sujet', ['moto' => $client['moto']], null, $langue))
                ->htmlTemplate('emails/garage_nouvelle_piece.html.twig')
                ->locale($langue)
                ->context([
                    'prenom' => $client['prenom'],
                    'moto' => $client['moto'],
                    'piece' => $piece,
                    'url_piece' => $this->catalogue->url($piece, $langue, true),
                    'url_garage' => $this->urlGenerator->generate('app_compte_garage', ['_locale' => $langue], UrlGeneratorInterface::ABSOLUTE_URL),
                ]));

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Envoi de l\'e-mail « nouvelle pièce pour votre moto » impossible', ['piece' => $piece['id'], 'exception' => $e]);

            return false;
        }
    }
}
