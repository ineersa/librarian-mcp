<?php

declare(strict_types=1);

namespace App\Index\Store;

use App\Index\IndexSettings;
use Doctrine\DBAL\Connection;
use Symfony\AI\Store\Bridge\Postgres\Distance;
use Symfony\AI\Store\Bridge\Postgres\Store;

/**
 * Native stores share Doctrine's PDO connection and transaction boundary.
 * A separate table per generation provides project isolation without query adapters.
 */
final readonly class IndexStoreFactory
{
    public function __construct(
        private Connection $connection,
        private IndexSettings $settings,
    ) {
    }

    public function forGeneration(int $projectId, int $generationId, bool $initialize = true): Store
    {
        if ($projectId < 1 || $generationId < 1) {
            throw new \InvalidArgumentException('Project and generation IDs must be positive integers.');
        }

        $pdo = $this->connection->getNativeConnection();
        if (!$pdo instanceof \PDO) {
            throw new \LogicException('Indexing requires Doctrine to use the PDO PostgreSQL driver.');
        }

        $store = new Store($pdo, \sprintf('librarian_p%d_g%d', $projectId, $generationId), distance: Distance::Cosine);
        if ($initialize) {
            $store->setup(['vector_size' => $this->settings->dimensions]);
        }

        return $store;
    }

    public function removeGeneration(int $projectId, int $generationId): void
    {
        $this->forGeneration($projectId, $generationId, initialize: false)->drop();
    }
}
