<?php

declare(strict_types=1);

namespace App\Backup;

use Doctrine\DBAL\Connection;

/**
 * `pg_dump --format=custom` (compressed; restore with pg_restore) of the Librarian database,
 * which holds the canonical observational memory. The password goes through PGPASSWORD,
 * never the command line.
 */
final readonly class PostgresDumper
{
    public function __construct(
        private Connection $connection,
    ) {
    }

    public function dump(string $target): void
    {
        $params = $this->connection->getParams();
        $command = ['pg_dump', '--format=custom', '--no-owner', '--file', $target];
        foreach (['host' => '--host', 'port' => '--port', 'user' => '--username', 'dbname' => '--dbname'] as $key => $option) {
            if (isset($params[$key]) && '' !== (string) $params[$key]) {
                $command[] = $option;
                $command[] = (string) $params[$key];
            }
        }

        ArchiveCommand::run($command, ['PGPASSWORD' => (string) ($params['password'] ?? '')]);
    }
}
