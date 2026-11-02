<?php

namespace App\Boutique;

use Doctrine\DBAL\Connection;

/**
 * Réglages du site (table `reglages`), dont le mode de la boutique :
 * « vente » (panier et commande) ou « vitrine » (consultation seulement).
 */
final class Reglages
{
    public const VENTE = 'vente';
    public const VITRINE = 'vitrine';

    /** @var array<string, ?string>|null lus une seule fois par requête */
    private ?array $valeurs = null;

    public function __construct(private readonly Connection $connection)
    {
    }

    public function get(string $cle, ?string $defaut = null): ?string
    {
        $this->valeurs ??= $this->connection->fetchAllKeyValue('SELECT cle, valeur FROM reglages');

        return $this->valeurs[$cle] ?? $defaut;
    }

    public function set(string $cle, ?string $valeur): void
    {
        $this->connection->executeStatement(
            'INSERT INTO reglages (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)',
            [$cle, $valeur]
        );
        $this->valeurs = null;
    }

    public function venteOuverte(): bool
    {
        return $this->get('boutique_mode', self::VENTE) !== self::VITRINE;
    }

    /** En vente, les prix sont toujours affichés ; en vitrine, selon le réglage. */
    public function prixVisibles(): bool
    {
        return $this->venteOuverte() || $this->get('vitrine_prix', '1') === '1';
    }
}
