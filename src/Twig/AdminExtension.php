<?php

namespace App\Twig;

use App\Admin\ResourceRegistry;
use Doctrine\DBAL\Connection;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Pastille du menu d'administration (ex. nombre de nouvelles demandes de pièces).
 */
class AdminExtension extends AbstractExtension
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly Connection $connection,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('admin_badge', $this->badge(...)),
        ];
    }

    public function badge(string $resource): int
    {
        $sql = $this->registry->get($resource)['badge_sql'] ?? null;

        return $sql ? (int) $this->connection->fetchOne($sql) : 0;
    }
}
