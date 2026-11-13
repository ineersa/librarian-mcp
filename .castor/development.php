<?php

declare(strict_types=1);

namespace AppTasks;

use Castor\Attribute\AsArgsAfterOptionEnd;
use Castor\Attribute\AsTask;
use Symfony\Component\Process\Process;

function checkoutId(): string
{
    $root = \dirname(__DIR__);
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(basename($root))), '-');

    return substr($slug, 0, 36).'-'.substr(hash('sha256', $root), 0, 10);
}

function stackNetwork(string $mode = 'browser'): string
{
    return stackProject($mode).'-net';
}

function stackProject(string $mode): string
{
    return 'librarian-'.checkoutId().'-'.$mode;
}

function stackUrl(string $mode): string
{
    $port = 'browser' === $mode ? browserPublishedPort() : developmentPublishedPort();

    return 'http://127.0.0.1:'.$port;
}

function developmentPublishedPort(): string
{
    return allocatePublishedPort('dev');
}

function browserPublishedPort(): string
{
    return allocatePublishedPort('browser');
}

function allocatePublishedPort(string $mode): string
{
    $directory = \dirname(__DIR__).'/var/tooling';
    if (is_link($directory)) {
        throw new \RuntimeException('Refusing symlinked runtime secrets.');
    }
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $portFile = $directory.'/http-'.$mode.'.port';
    if (is_link($portFile)) {
        throw new \RuntimeException('Refusing symlinked published port file.');
    }
    if (!is_file($portFile)) {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        if (false === $socket) {
            throw new \RuntimeException('Cannot allocate a local published port.');
        }
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        if (false === $address) {
            throw new \RuntimeException('Cannot inspect local published port.');
        }
        $separator = strrpos($address, ':');
        if (false === $separator) {
            throw new \RuntimeException('Invalid local published address.');
        }
        file_put_contents($portFile, substr($address, $separator + 1));
        chmod($portFile, 0600);
    }
    $port = trim((string) file_get_contents($portFile));
    if (!ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535) {
        throw new \RuntimeException('Invalid local published port.');
    }

    return $port;
}

function browserPublishedPostgresPort(): string
{
    return allocatePublishedPort('browser-postgres');
}

/**
 * @return array{db: string, user: string, password: string}
 */
function postgresCredentials(string $mode): array
{
    $directory = \dirname(__DIR__).'/var/tooling';
    if (is_link($directory)) {
        throw new \RuntimeException('Refusing symlinked runtime secrets.');
    }
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }

    if ('browser' === $mode) {
        return [
            'db' => 'librarian_browser',
            'user' => 'librarian',
            'password' => 'browser-synthetic-only',
        ];
    }

    $secret = $directory.'/postgres-'.$mode.'.password';
    if (is_link($secret)) {
        throw new \RuntimeException('Refusing symlinked Postgres secret.');
    }
    if (!is_file($secret)) {
        $file = fopen($secret, 'xb');
        if (false === $file) {
            throw new \RuntimeException('Cannot create Postgres secret.');
        }
        chmod($secret, 0600);
        fwrite($file, bin2hex(random_bytes(24)));
        fclose($file);
    }
    $password = trim((string) file_get_contents($secret));
    if ('' === $password) {
        throw new \RuntimeException('Invalid Postgres secret.');
    }

    return [
        'db' => 'librarian',
        'user' => 'librarian',
        'password' => $password,
    ];
}

function postgresDatabaseUrl(string $mode, bool $forHostNetwork): string
{
    $credentials = postgresCredentials($mode);
    $host = 'postgres';
    $port = '5432';
    if ($forHostNetwork) {
        if ('browser' !== $mode) {
            throw new \InvalidArgumentException('Host-network Postgres URLs are only for the browser stack.');
        }
        $host = '127.0.0.1';
        $port = browserPublishedPostgresPort();
    }

    return \sprintf(
        'postgresql://%s:%s@%s:%s/%s?serverVersion=17&charset=utf8',
        rawurlencode($credentials['user']),
        rawurlencode($credentials['password']),
        $host,
        $port,
        rawurlencode($credentials['db']),
    );
}

function postgresLockDsn(string $mode, bool $forHostNetwork): string
{
    return postgresDatabaseUrl($mode, $forHostNetwork);
}

function assertOwnedBrowserDatabaseUrl(string $databaseUrl): void
{
    $credentials = postgresCredentials('browser');
    $parts = parse_url($databaseUrl);
    if (!\is_array($parts) || 'postgresql' !== ($parts['scheme'] ?? null)) {
        throw new \RuntimeException('Browser fixtures require a PostgreSQL DATABASE_URL.');
    }
    $user = rawurldecode((string) ($parts['user'] ?? ''));
    $pass = rawurldecode((string) ($parts['pass'] ?? ''));
    $path = ltrim((string) ($parts['path'] ?? ''), '/');
    $host = (string) ($parts['host'] ?? '');
    $port = (string) ($parts['port'] ?? '5432');
    $expectedPort = browserPublishedPostgresPort();
    if (
        $credentials['user'] !== $user
        || $credentials['password'] !== $pass
        || $credentials['db'] !== $path
        || !\in_array($host, ['127.0.0.1', 'localhost'], true)
        || $expectedPort !== $port
    ) {
        throw new \RuntimeException('Browser fixtures require the exact isolated checkout-owned Postgres URL.');
    }
}

function isolatedPostgresDatabaseName(string $identifier): string
{
    if (1 !== preg_match('/\A[a-z0-9-]+\z/D', $identifier)) {
        throw new \InvalidArgumentException('Use a lowercase diagnostic database identifier.');
    }

    return 'librarian_iso_'.str_replace('-', '_', $identifier);
}

function isolatedPostgresDatabaseUrl(string $identifier): string
{
    // Diagnostic DBs live on the checkout-owned browser Postgres service so
    // networked tooling can reuse the already-supported private browser network.
    $credentials = postgresCredentials('browser');
    $db = isolatedPostgresDatabaseName($identifier);

    return \sprintf(
        'postgresql://%s:%s@postgres:5432/%s?serverVersion=17&charset=utf8',
        rawurlencode($credentials['user']),
        rawurlencode($credentials['password']),
        rawurlencode($db),
    );
}

function isolatedPostgresLockDsn(string $identifier): string
{
    return isolatedPostgresDatabaseUrl($identifier);
}

/**
 * Starts only this checkout's browser Postgres service for diagnostic/fresh DB work.
 * Does not start php/worker and does not reset any existing databases.
 */
function ensureBrowserPostgresService(): void
{
    foreach ([\dirname(__DIR__).'/var', \dirname(__DIR__).'/var/browser-live'] as $storage) {
        if (is_link($storage)) {
            throw new \RuntimeException('Refusing symlinked browser storage.');
        }
    }

    ensureStackNetwork('browser');
    stackRun('browser', ['up', '-d', '--wait', 'postgres']);
}

/** @return list<string> */
function stackCommand(string $mode): array
{
    return ['docker', 'compose', '--project-name', stackProject($mode), '-f', 'compose.yaml', '-f', 'compose.override.yaml', ...('browser' === $mode ? ['-f', 'compose.browser.yaml'] : (is_file(\dirname(__DIR__).'/compose.sources.yaml') ? ['-f', 'compose.sources.yaml'] : []))];
}

/** @return array<string, string> */
function stackEnvironment(string $mode): array
{
    $directory = \dirname(__DIR__).'/var/tooling';
    if (is_link($directory)) {
        throw new \RuntimeException('Refusing symlinked runtime secrets.');
    }
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $secret = $directory.'/mercure-'.$mode.'.key';
    if (is_link($secret)) {
        throw new \RuntimeException('Refusing symlinked Mercure secret.');
    }
    if (!is_file($secret)) {
        $file = fopen($secret, 'xb');
        if (false === $file) {
            throw new \RuntimeException('Cannot create Mercure secret.');
        }
        chmod($secret, 0600);
        fwrite($file, bin2hex(random_bytes(32)));
        fclose($file);
    }

    $url = stackUrl($mode);
    $jwt = trim((string) file_get_contents($secret));

    $credentials = postgresCredentials($mode);
    $databaseUrl = postgresDatabaseUrl($mode, forHostNetwork: false);
    $lockDsn = postgresLockDsn($mode, forHostNetwork: false);

    return [
        'LIBRARIAN_CHECKOUT_ID' => checkoutId(),
        'LIBRARIAN_PROJECT' => stackProject($mode),
        'LIBRARIAN_NETWORK' => stackNetwork($mode),
        'LIBRARIAN_PUBLIC_URL' => $url,
        'HTTP_PORT' => 'browser' === $mode ? browserPublishedPort() : developmentPublishedPort(),
        'POSTGRES_PORT' => 'browser' === $mode ? browserPublishedPostgresPort() : '',
        'POSTGRES_DB' => $credentials['db'],
        'POSTGRES_USER' => $credentials['user'],
        'POSTGRES_PASSWORD' => $credentials['password'],
        'DATABASE_URL' => $databaseUrl,
        'LOCK_DSN' => $lockDsn,
        'MERCURE_JWT_SECRET' => $jwt,
        'MERCURE_PUBLISHER_JWT_KEY' => $jwt,
        'MERCURE_SUBSCRIBER_JWT_KEY' => $jwt,
        'MERCURE_CORS_ORIGINS' => $url.' http://php:8080',
        'LOCAL_UID' => (string) posix_getuid(),
        'LOCAL_GID' => (string) posix_getgid(),
    ];
}

/** @param list<string> $arguments */
function stackRun(string $mode, array $arguments): void
{
    $process = new Process([...stackCommand($mode), ...$arguments], \dirname(__DIR__), stackEnvironment($mode), timeout: 600);
    runTaskProcess($process);
    echo $process->getOutput();
    echo $process->getErrorOutput();
}

function ensureStackNetwork(string $mode): void
{
    $network = stackNetwork($mode);
    $inspect = new Process(['docker', 'network', 'inspect', '--format', '{{index .Labels "librarian.stack.owner"}}', $network]);
    if (0 === runTaskProcess($inspect, false)) {
        if (hash('sha256', \dirname(__DIR__)) !== trim($inspect->getOutput())) {
            throw new \RuntimeException('Refusing foreign librarian stack network: '.$network);
        }

        return;
    }
    if (false === stripos($inspect->getErrorOutput(), 'no such') && false === stripos($inspect->getErrorOutput(), 'not found')) {
        throw new \RuntimeException('Cannot inspect librarian stack network: '.$inspect->getErrorOutput());
    }
    $create = new Process(['docker', 'network', 'create', '--label', 'librarian.stack.owner='.hash('sha256', \dirname(__DIR__)), $network]);
    runTaskProcess($create);
}

function developmentStack(string $mode, string $action): int
{
    $exit = report($mode.':stack:'.$action, ['docker', 'compose', $action], runtime: false, operation: static function () use ($mode, $action): int {
        if ('status' === $action) {
            stackRun($mode, ['ps', '--format=json']);
            echo stackUrl($mode)."\n";

            return 0;
        }

        if ('up' === $action) {
            TaskExecution::$current?->throwIfInterrupted();
            $secret = \dirname(__DIR__).'/.env.local';
            if ('dev' === $mode && !file_exists($secret)) {
                $file = fopen($secret, 'xb');
                if (false === $file) {
                    throw new \RuntimeException('Cannot create the private development environment file.');
                }
                chmod($secret, 0600);
                fwrite($file, 'APP_SECRET='.bin2hex(random_bytes(32))."\n");
                fclose($file);
            }
            foreach ([\dirname(__DIR__).'/var', \dirname(__DIR__).'/var/browser-live'] as $storage) {
                if (is_link($storage)) {
                    throw new \RuntimeException('Refusing symlinked application storage.');
                }
            }
            if ('browser' === $mode) {
                foreach ([
                    '/var/browser-live',
                    '/var/browser-live/cache',
                    '/var/browser-live/log',
                    '/var/browser-live/mcp-sessions',
                    '/var/browser-live/indexes',
                    '/var/browser-live/indexes/locks',
                ] as $relative) {
                    $path = \dirname(__DIR__).$relative;
                    if (!is_dir($path)) {
                        mkdir($path, 0700, true);
                    }
                }
                $fixtures = report('test:sources:prepare', ['php', 'tests/Support/prepare_project_sources.php']);
                if (0 !== $fixtures) {
                    return $fixtures;
                }
            }
            ensureStackNetwork($mode);
            stackRun($mode, ['build']);
            stackRun($mode, ['up', '-d', '--wait']);
        } else {
            stackRun($mode, ['down']);
        }
        echo stackUrl($mode)."\n";

        return 0;
    });
    if (0 === $exit && 'stop' !== $action) {
        echo stackUrl($mode)."\n";
    }

    return $exit;
}

#[AsTask(namespace: 'dev', name: 'stack:up', description: 'Build and start this checkout on a private published URL.')]
function developmentStackUp(): int
{
    return developmentStack('dev', 'up');
}

#[AsTask(namespace: 'dev', name: 'stack:status', description: 'Inspect this checkout and print its development URL.')]
function developmentStackStatus(): int
{
    return developmentStack('dev', 'status');
}

#[AsTask(namespace: 'dev', name: 'stack:stop', description: 'Stop this checkout only, preserving data.')]
function developmentStackStop(): int
{
    return developmentStack('dev', 'stop');
}

/** @param list<string> $arguments */
#[AsTask(namespace: 'browser', name: 'live:test', description: 'Run browser tests against the isolated live stack; pass native PHPUnit arguments after --.')]
function browserLiveTest(#[AsArgsAfterOptionEnd] array $arguments = [], bool $headed = false): int
{
    if ([] === $arguments) {
        throw new \InvalidArgumentException('Pass the PHPUnit test selection after --.');
    }

    $databaseUrl = postgresDatabaseUrl('browser', forHostNetwork: true);
    $lockDsn = postgresLockDsn('browser', forHostNetwork: true);

    return report('browser:live:test', ['env', 'DATABASE_URL='.$databaseUrl, 'LOCK_DSN='.$lockDsn, 'LIBRARIAN_BROWSER_REUSE_STORAGE=1', 'PLAYWRIGHT_HEADLESS='.($headed ? 'false' : 'true'), 'LIBRARIAN_BROWSER_URL='.stackUrl('browser'), 'php', 'vendor/bin/phpunit', ...$arguments, '--fail-on-empty-test-suite', '--log-junit', 'var/qa/browser-live-test/junit.xml'], headed: $headed, hostNetwork: true);
}

#[AsTask(namespace: 'browser', name: 'stack:up', description: 'Build and start only the isolated browser-test application.')]
function browserStackUp(): int
{
    return developmentStack('browser', 'up');
}

#[AsTask(namespace: 'browser', name: 'stack:status', description: 'Inspect only the isolated browser-test application.')]
function browserStackStatus(): int
{
    return developmentStack('browser', 'status');
}

#[AsTask(namespace: 'browser', name: 'stack:stop', description: 'Stop only the isolated browser-test application without deleting storage.')]
function browserStackStop(): int
{
    return developmentStack('browser', 'stop');
}

#[AsTask(namespace: 'browser', name: 'stack:restart', description: 'Restart one verified service in this checkout\'s isolated browser stack: php, worker, or postgres.')]
function browserStackRestart(string $service = 'php', bool $force = false): int
{
    if (!\in_array($service, ['php', 'worker', 'postgres'], true)) {
        throw new \InvalidArgumentException('Choose a verified browser service: php, worker, or postgres.');
    }

    return report('browser:stack:restart', [...stackCommand('browser'), 'restart', $service], runtime: false, operation: static function () use ($service, $force): int {
        if ($force) {
            stackRun('browser', ['kill', '--signal', 'SIGKILL', $service]);
        }
        stackRun('browser', ['restart', $service]);

        return 0;
    });
}

#[AsTask(namespace: 'browser', name: 'stack:restart-worker', description: 'Restart only this checkout\'s isolated browser worker to load changed code.')]
function browserStackRestartWorker(bool $force = false): int
{
    return browserStackRestart('worker', $force);
}

/** @param list<string> $arguments */
#[AsTask(namespace: 'browser', name: 'stack:console', description: 'Run Symfony console in this checkout\'s running isolated browser stack; pass arguments after --.')]
function browserStackConsole(#[AsArgsAfterOptionEnd] array $arguments = [], bool $worker = false): int
{
    $command = ['exec', '-T', '--user', posix_getuid().':'.posix_getgid(), $worker ? 'worker' : 'php', 'php', 'bin/console', ...$arguments];

    return report('browser:stack:console', [...stackCommand('browser'), ...$command], runtime: false, operation: static function () use ($command): int {
        stackRun('browser', $command);

        return 0;
    });
}

#[AsTask(namespace: 'browser', name: 'stack:logs', description: 'Capture a bounded tail of this checkout browser stack logs.')]
function browserStackLogs(int $lines = 30): int
{
    if ($lines < 1 || $lines > 100) {
        throw new \InvalidArgumentException('Choose between 1 and 100 log lines per service.');
    }

    return report('browser:stack:logs', [...stackCommand('browser'), 'logs', '--tail='.$lines], runtime: false, operation: static function () use ($lines): int {
        stackRun('browser', ['logs', '--no-color', '--tail='.$lines]);

        return 0;
    });
}
