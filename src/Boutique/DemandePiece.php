<?php

namespace App\Boutique;

use App\Entity\Client;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * « Vous ne trouvez pas votre pièce ? » : demandes envoyées au club, avec photos.
 *
 * Les photos sont rangées hors du dossier public (var/demandes/{id}/) :
 * seuls les administrateurs peuvent les voir.
 */
final class DemandePiece
{
    /** Statuts (libellé de l'administration => valeur en base) */
    public const STATUTS = [
        'Nouvelle' => 'nouvelle',
        'En cours de recherche' => 'en_cours',
        'Trouvée' => 'trouvee',
        'Introuvable' => 'introuvable',
    ];

    public const PHOTOS_MAX = 3;

    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly Connection $connection,
        private readonly MailerInterface $mailer,
        private readonly TranslatorInterface $translator,
        private readonly LoggerInterface $logger,
        #[Autowire('%kernel.project_dir%/var/demandes')]
        private readonly string $dossier,
        #[Autowire('%env(MAILER_FROM)%')]
        private readonly string $expediteur,
        #[Autowire('%env(CLUB_EMAIL)%')]
        private readonly string $emailClub,
    ) {
        $this->filesystem = new Filesystem();
    }

    /**
     * Enregistre la demande, range les photos, prévient le club et envoie un accusé de réception.
     *
     * @param array{nom: string, email: string, telephone: ?string, modele: string, modele_autre: ?string, annee: ?string, description: string} $donnees
     * @param list<UploadedFile> $photos
     *
     * @return int numéro de la demande
     */
    public function enregistrer(array $donnees, array $photos, ?Client $client, string $langue): int
    {
        $modeleId = ctype_digit((string) $donnees['modele']) ? (int) $donnees['modele'] : null;

        $this->connection->insert('demandes_pieces', [
            'client_id' => $client?->getId(),
            'nom' => trim($donnees['nom']),
            'email' => mb_strtolower(trim($donnees['email'])),
            'telephone' => $donnees['telephone'] ? trim($donnees['telephone']) : null,
            'modele_id' => $modeleId,
            'modele_autre' => $modeleId ? null : (trim((string) $donnees['modele_autre']) ?: null),
            'annee' => $donnees['annee'] ? trim($donnees['annee']) : null,
            'description' => trim($donnees['description']),
            'langue' => $langue,
        ]);
        $id = (int) $this->connection->lastInsertId();

        // photos : var/demandes/{id}/1.jpg, 2.png…
        $noms = [];
        foreach (array_slice($photos, 0, self::PHOTOS_MAX) as $i => $photo) {
            $nom = ($i + 1) . '.' . strtolower($photo->guessExtension() ?: 'jpg');
            $photo->move($this->dossier . '/' . $id, $nom);
            $noms[] = $nom;
        }
        if ($noms !== []) {
            $this->connection->update('demandes_pieces', ['photos' => json_encode($noms)], ['id' => $id]);
        }

        $demande = $this->trouver($id);
        $this->prevenirClub($demande);
        $this->envoyerAuClient($demande, 'email.demande.recue_sujet', 'emails/demande_accuse.html.twig');

        return $id;
    }

    /**
     * Le club a écrit une réponse dans l'administration : elle part par e-mail au client.
     */
    public function envoyerReponse(int $id): bool
    {
        $demande = $this->trouver($id);
        if ($demande === null || trim((string) $demande['reponse']) === '') {
            return false;
        }

        $envoye = $this->envoyerAuClient($demande, 'email.demande.reponse_sujet', 'emails/demande_reponse.html.twig');
        if ($envoye) {
            $this->connection->executeStatement(
                "UPDATE demandes_pieces SET date_reponse = NOW(), statut = IF(statut = 'nouvelle', 'en_cours', statut) WHERE id = ?",
                [$id]
            );
        }

        return $envoye;
    }

    public function trouver(int $id): ?array
    {
        $demande = $this->connection->fetchAssociative(
            'SELECT d.*, m.nom AS modele_nom FROM demandes_pieces d LEFT JOIN modeles_moto m ON m.id = d.modele_id WHERE d.id = ?',
            [$id]
        );

        return $demande === false ? null : self::completer($demande);
    }

    /**
     * Demandes d'un client : celles faites connecté, et celles faites avec la même adresse.
     */
    public function demandesDuClient(Client $client): array
    {
        return array_map(self::completer(...), $this->connection->fetchAllAssociative(
            'SELECT d.*, m.nom AS modele_nom FROM demandes_pieces d LEFT JOIN modeles_moto m ON m.id = d.modele_id
             WHERE d.client_id = ? OR d.email = ? ORDER BY d.date_demande DESC, d.id DESC',
            [$client->getId(), $client->getEmail()]
        ));
    }

    /** Chemin du fichier d'une photo (affichage dans l'administration). */
    public function cheminPhoto(int $id, int $numero): ?string
    {
        $demande = $this->trouver($id);
        $nom = $demande['photos'][$numero - 1] ?? null;
        $chemin = $nom ? $this->dossier . '/' . $id . '/' . basename($nom) : null;

        return $chemin && is_file($chemin) ? $chemin : null;
    }

    /** Photos d'une demande supprimée depuis l'administration. */
    public function supprimerFichiers(int $id): void
    {
        $this->filesystem->remove($this->dossier . '/' . $id);
    }

    /** Suppression du compte : ses demandes et leurs photos. */
    public function supprimerPourClient(Client $client): void
    {
        $ids = $this->connection->fetchFirstColumn('SELECT id FROM demandes_pieces WHERE client_id = ? OR email = ?', [$client->getId(), $client->getEmail()]);
        foreach ($ids as $id) {
            $this->supprimerFichiers((int) $id);
        }
        $this->connection->executeStatement('DELETE FROM demandes_pieces WHERE client_id = ? OR email = ?', [$client->getId(), $client->getEmail()]);
    }

    /** Pour l'export « Mes données ». */
    public function exporter(Client $client): array
    {
        return array_map(fn (array $d) => [
            'date_demande' => $d['date_demande'],
            'modele' => $d['modele_affiche'],
            'annee' => $d['annee'],
            'description' => $d['description'],
            'nombre_photos' => count($d['photos']),
            'statut' => $d['statut'],
            'reponse' => $d['reponse'],
            'date_reponse' => $d['date_reponse'],
        ], $this->demandesDuClient($client));
    }

    private function prevenirClub(array $demande): void
    {
        $email = (new TemplatedEmail())
            ->from(new Address($this->expediteur, 'Bultaco Club France'))
            ->to($this->emailClub)
            ->replyTo(new Address($demande['email'], $demande['nom']))
            ->subject(sprintf('Demande de pièce n° %d : %s', $demande['id'], $demande['modele_affiche'] ?: 'modèle non précisé'))
            ->htmlTemplate('emails/demande_club.html.twig')
            ->locale('fr')
            ->context(['demande' => $demande]);
        foreach ($demande['photos'] as $i => $nom) {
            $email->attachFromPath($this->dossier . '/' . $demande['id'] . '/' . $nom, sprintf('demande-%d-photo-%d.%s', $demande['id'], $i + 1, pathinfo($nom, PATHINFO_EXTENSION)));
        }
        $this->envoyer($email, $demande['id']);
    }

    private function envoyerAuClient(array $demande, string $sujet, string $template): bool
    {
        $langue = $demande['langue'];

        return $this->envoyer((new TemplatedEmail())
            ->from(new Address($this->expediteur, 'Bultaco Club France'))
            ->to(new Address($demande['email'], $demande['nom']))
            ->replyTo($this->emailClub) // le client peut répondre directement au club
            ->subject($this->translator->trans($sujet, ['numero' => $demande['id']], null, $langue))
            ->htmlTemplate($template)
            ->locale($langue)
            ->context(['demande' => $demande]), $demande['id']);
    }

    private function envoyer(TemplatedEmail $email, int $id): bool
    {
        try {
            $this->mailer->send($email);

            return true;
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Envoi d\'un e-mail de demande de pièce impossible', ['demande' => $id, 'exception' => $e]);

            return false;
        }
    }

    private static function completer(array $demande): array
    {
        $demande['photos'] = json_decode((string) $demande['photos'], true) ?: [];
        $demande['modele_affiche'] = $demande['modele_nom'] ?? $demande['modele_autre'];

        return $demande;
    }
}
