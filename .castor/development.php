<?php

declare(strict_types=1);

namespace AppTasks;

use Castor\Attribute\AsArgsAfterOptionEnd;
use Castor\Attribute\AsTask;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Process\Process;

function checkoutId(): string
{
    $root = \dirname(__DIR__);
    $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(basename($root))), '-');

    return substr($slug, 0, 36).'-'.substr(hash('sha256', $root), 0, 10);
}

function repositoryState(): string
{
    $process = new Process(['git', 'rev-parse', '--path-format=absolute', '--git-common-dir'], \dirname(__DIR__));
    runTaskProcess($process);

    return trim($process->getOutput());
}

function developmentNetwork(): string
{
    return 'librarian-development-'.posix_getuid().'-'.substr(hash('sha256', repositoryState()), 0, 10);
}

function proxyName(): string
{
    return 'librarian-proxy-'.posix_getuid().'-'.substr(hash('sha256', repositoryState()), 0, 10);
}

function stackNetwork(string $mode = 'browser'): string
{
    return stackProject($mode).'-net';
}

function stackProject(string $mode): string
{
    return 'librarian-'.checkoutId().'-'.$mode;
}

function proxyPort(): string
{
    $explicit = explicitProxyPort();
    $published = publishedProxyPort();
    if (null !== $explicit && null !== $published && $published !== $explicit) {
        throw new \RuntimeException(\sprintf('LIBRARIAN_PROXY_PORT=%s conflicts with the repository-shared proxy on %s. Run `vendor/bin/castor dev:proxy:stop --for-upgrade` before recreating it on a different port.', $explicit, $published));
    }

    return $explicit ?? $published ?? '8082';
}

function validateProxyPort(string $port): string
{
    if (!ctype_digit($port) || (int) $port < 1024 || (int) $port > 65535) {
        throw new \RuntimeException('Invalid LIBRARIAN_PROXY_PORT.');
    }

    return $port;
}

function explicitProxyPort(): ?string
{
    $port = getenv('LIBRARIAN_PROXY_PORT');
    if (false !== $port && '' !== $port) {
        return validateProxyPort($port);
    }

    $path = \dirname(__DIR__).'/.env.local';
    if (is_link($path) || !is_file($path)) {
        return null;
    }

    $values = (new Dotenv())->parse((string) file_get_contents($path), $path);
    if (!isset($values['LIBRARIAN_PROXY_PORT']) || '' === $values['LIBRARIAN_PROXY_PORT']) {
        return null;
    }

    return validateProxyPort($values['LIBRARIAN_PROXY_PORT']);
}

function publishedProxyPort(): ?string
{
    if (!assertProxyOwner('container', proxyName())) {
        return null;
    }

    $bindings = trim(developmentProcess(['docker', 'inspect', '--format', '{{json .HostConfig.PortBindings}}', proxyName()]));
    $decoded = json_decode($bindings, true);
    if (!\is_array($decoded) || [] === $decoded) {
        throw new \RuntimeException('The shared development proxy has no published port bindings.');
    }

    $ports = [];
    foreach ($decoded as $containerPort => $hostBindings) {
        if (!\is_string($containerPort) || !\is_array($hostBindings) || [] === $hostBindings) {
            continue;
        }
        if (1 !== preg_match('/\A(\d+)\/tcp\z/D', $containerPort, $matches)) {
            continue;
        }
        foreach ($hostBindings as $binding) {
            if (!\is_array($binding)) {
                continue;
            }
            $hostPort = (string) ($binding['HostPort'] ?? '');
            if ('' === $hostPort) {
                continue;
            }
            if ($hostPort !== $matches[1]) {
                throw new \RuntimeException('The shared development proxy publish mapping is not host:container on the same port.');
            }
            $ports[$hostPort] = true;
        }
    }
    if (1 !== \count($ports)) {
        throw new \RuntimeException('The shared development proxy must publish exactly one TCP port.');
    }

    return validateProxyPort((string) array_key_first($ports));
}

function stackUrl(string $mode): string
{
    return 'http://'.checkoutId().('browser' === $mode ? '.browser' : '').'.librarian.localhost:'.proxyPort();
}

/** @param list<string> $command */
function developmentProcess(array $command): string
{
    $process = new Process($command, \dirname(__DIR__), timeout: 600);
    runTaskProcess($process);
    echo $process->getErrorOutput();

    return $process->getOutput();
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

function postgresDatabaseUrl(string $mode): string
{
    $credentials = postgresCredentials($mode);

    return \sprintf(
        'postgresql://%s:%s@postgres:5432/%s?serverVersion=17&charset=utf8',
        rawurlencode($credentials['user']),
        rawurlencode($credentials['password']),
        rawurlencode($credentials['db']),
    );
}

function postgresLockDsn(string $mode): string
{
    return postgresDatabaseUrl($mode);
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
    if (
        $credentials['user'] !== $user
        || $credentials['password'] !== $pass
        || $credentials['db'] !== $path
        || 'postgres' !== $host
        || '5432' !== $port
    ) {
        throw new \RuntimeException('Browser fixtures require the exact isolated checkout-owned private Postgres URL.');
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
    ensureDevelopmentNetwork();
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
    $databaseUrl = postgresDatabaseUrl($mode);
    $lockDsn = postgresLockDsn($mode);
    $hostname = (string) parse_url($url, \PHP_URL_HOST);

    return [
        'LIBRARIAN_CHECKOUT_ID' => checkoutId(),
        'LIBRARIAN_PROJECT' => stackProject($mode),
        'LIBRARIAN_NETWORK' => stackNetwork($mode),
        'LIBRARIAN_DEVELOPMENT_NETWORK' => developmentNetwork(),
        'LIBRARIAN_PUBLIC_URL' => $url,
        'LIBRARIAN_PROXY_PORT' => proxyPort(),
        'MCP_ALLOWED_HOSTS' => implode(',', ['localhost', '127.0.0.1', 'php', 'server', $hostname]),
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

function proxyState(): string
{
    $directory = repositoryState().'/librarian-development';
    if (is_link($directory)) {
        throw new \RuntimeException('Refusing symlinked proxy state.');
    }
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }

    return $directory;
}

function assertProxyOwner(string $kind, string $name): bool
{
    $inspect = new Process(['docker', $kind, 'inspect', '--format', '{{index .Labels "librarian.proxy.owner"}}', $name]);
    if ('container' === $kind) {
        $inspect = new Process(['docker', 'inspect', '--format', '{{index .Config.Labels "librarian.proxy.owner"}}', $name]);
    }
    if (0 !== runTaskProcess($inspect, false)) {
        if (false === stripos($inspect->getErrorOutput(), 'no such') && false === stripos($inspect->getErrorOutput(), 'not found')) {
            throw new \RuntimeException('Cannot inspect development proxy resource: '.$inspect->getErrorOutput());
        }

        return false;
    }
    if ((string) posix_getuid() !== trim($inspect->getOutput())) {
        throw new \RuntimeException('Refusing foreign development proxy resource: '.$name);
    }

    return true;
}

function proxyAddress(): string
{
    if (!assertProxyOwner('container', proxyName())) {
        throw new \RuntimeException('Start a development or browser stack before running live routing scenarios.');
    }
    $address = trim(developmentProcess(['docker', 'inspect', '--format', '{{with index .NetworkSettings.Networks "'.developmentNetwork().'"}}{{.IPAddress}}{{end}}', proxyName()]));
    if (false === filter_var($address, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV4)) {
        throw new \RuntimeException('The development proxy has no network address.');
    }

    return $address;
}

function ensureDevelopmentNetwork(): void
{
    if (!assertProxyOwner('network', developmentNetwork())) {
        developmentProcess(['docker', 'network', 'create', '--label', 'librarian.proxy.owner='.posix_getuid(), developmentNetwork()]);
    }
}

function ensureProxy(string $state): void
{
    ensureDevelopmentNetwork();
    if (assertProxyOwner('container', proxyName())) {
        developmentProcess(['docker', 'start', proxyName()]);

        return;
    }
    // Reuse the application image's Caddy binary; no application or Docker socket is mounted.
    $port = proxyPort();
    developmentProcess(['docker', 'run', '-d', '--name', proxyName(), '--label', 'librarian.proxy.owner='.posix_getuid(), '--network', developmentNetwork(), '--publish', '127.0.0.1:'.$port.':'.$port, '--volume', $state.':/etc/librarian:ro', '--env', 'XDG_CONFIG_HOME=/tmp/config', '--env', 'XDG_DATA_HOME=/tmp/data', '--no-healthcheck', 'librarian-'.checkoutId().'-php:dev', 'frankenphp', 'run', '--config', '/etc/librarian/Caddyfile']);
}

/** @param array<string, string> $routes */
function writeProxyConfig(string $state, array $routes): void
{
    TaskExecution::$current?->throwIfInterrupted();
    $port = proxyPort();
    $config = "{\n\tadmin localhost:2019\n\tauto_https off\n}\n:{$port} {\n\trespond \"No development route registered\" 404\n}\n";
    ksort($routes);
    foreach ($routes as $hostname => $upstream) {
        if (1 !== preg_match('/^[a-z0-9.-]+\.librarian\.localhost$/D', $hostname) || 1 !== preg_match('/^librarian-[a-z0-9-]+:(8080)$/D', $upstream)) {
            throw new \RuntimeException('Invalid development proxy route.');
        }
        $config .= "http://{$hostname}:{$port} {\n\treverse_proxy {$upstream}\n}\n";
    }
    file_put_contents($state.'/Caddyfile.next', $config);
    TaskExecution::$current?->throwIfInterrupted();
    rename($state.'/Caddyfile.next', $state.'/Caddyfile');
}

function developmentStack(string $mode, string $action): int
{
    $exit = report($mode.':stack:'.$action, ['docker', 'compose', $action], runtime: false, operation: static function () use ($mode, $action): int {
        if ('status' === $action) {
            stackRun($mode, ['ps', '--format=json']);
            echo stackUrl($mode)."\n";

            return 0;
        }
        $state = proxyState();
        $lock = fopen($state.'/lock', 'cb');
        if (false === $lock || !flock($lock, \LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the shared development proxy.');
        }
        try {
            $routes = [];
            if (is_file($state.'/routes.json')) {
                $stored = json_decode((string) file_get_contents($state.'/routes.json'), true, flags: \JSON_THROW_ON_ERROR);
                if (!\is_array($stored)) {
                    throw new \RuntimeException('Invalid development route registry.');
                }
                foreach ($stored as $host => $target) {
                    if (!\is_string($host) || !\is_string($target)) {
                        throw new \RuntimeException('Invalid development route registration.');
                    }
                    $routes[$host] = $target;
                }
            }
            $hostname = (string) parse_url(stackUrl($mode), \PHP_URL_HOST);
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
                writeProxyConfig($state, $routes);
                ensureProxy($state);
                stackRun($mode, ['up', '-d', '--wait']);
                $routes[$hostname] = stackProject($mode).':8080';
            } else {
                stackRun($mode, ['down']);
                unset($routes[$hostname]);
            }
            writeProxyConfig($state, $routes);
            if (assertProxyOwner('container', proxyName()) && 'true' === trim(developmentProcess(['docker', 'inspect', '--format', '{{.State.Running}}', proxyName()]))) {
                developmentProcess(['docker', 'exec', proxyName(), 'frankenphp', 'reload', '--config', '/etc/librarian/Caddyfile']);
            }
            TaskExecution::$current?->throwIfInterrupted();
            file_put_contents($state.'/routes.json.next', json_encode($routes, \JSON_THROW_ON_ERROR)."\n");
            TaskExecution::$current?->throwIfInterrupted();
            rename($state.'/routes.json.next', $state.'/routes.json');
            echo stackUrl($mode)."\n";
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }

        return 0;
    });
    if (0 === $exit && 'stop' !== $action) {
        echo stackUrl($mode)."\n";
    }

    return $exit;
}

#[AsTask(namespace: 'dev', name: 'stack:up', description: 'Build and start this checkout behind its local hostname.')]
function developmentStackUp(): int
{
    return developmentStack('dev', 'up');
}

#[AsTask(namespace: 'dev', name: 'stack:status', description: 'Inspect this checkout and print its development URL.')]
function developmentStackStatus(): int
{
    return developmentStack('dev', 'status');
}

#[AsTask(namespace: 'dev', name: 'stack:stop', description: 'Stop this checkout only, preserving data and the shared proxy.')]
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

    $databaseUrl = postgresDatabaseUrl('browser');
    $lockDsn = postgresLockDsn('browser');
    assertOwnedBrowserDatabaseUrl($databaseUrl);

    return report('browser:live:test', ['env', 'DATABASE_URL='.$databaseUrl, 'LOCK_DSN='.$lockDsn, 'LIBRARIAN_BROWSER_REUSE_STORAGE=1', 'PLAYWRIGHT_HEADLESS='.($headed ? 'false' : 'true'), 'LIBRARIAN_BROWSER_URL='.stackUrl('browser'), 'LIBRARIAN_PROXY_IP='.proxyAddress(), 'php', 'vendor/bin/phpunit', ...$arguments, '--fail-on-empty-test-suite', '--log-junit', 'var/qa/browser-live-test/junit.xml'], headed: $headed, networked: true);
}

#[AsTask(namespace: 'dev', name: 'proxy:stop', description: 'Stop the shared proxy; --for-upgrade permits registered routes and removes only its owned container.')]
function developmentProxyStop(bool $forUpgrade = false): int
{
    return report('dev:proxy:stop', ['docker', 'stop', proxyName()], runtime: false, operation: static function () use ($forUpgrade): int {
        $state = proxyState();
        $lock = fopen($state.'/lock', 'cb');
        if (false === $lock || !flock($lock, \LOCK_EX)) {
            throw new \RuntimeException('Cannot lock the shared development proxy.');
        }
        try {
            if (!$forUpgrade && is_file($state.'/routes.json') && [] !== json_decode((string) file_get_contents($state.'/routes.json'), true, flags: \JSON_THROW_ON_ERROR)) {
                throw new \RuntimeException('Stop registered checkout stacks before stopping the shared proxy.');
            }
            if (assertProxyOwner('container', proxyName())) {
                developmentProcess(['docker', 'stop', proxyName()]);
                if ($forUpgrade) {
                    developmentProcess(['docker', 'rm', proxyName()]);
                    echo "Shared proxy removed for upgrade; registered routes and application stacks are preserved. Run dev:stack:up from the prepared checkout to restore all preview URLs.\n";
                }
            }
        } finally {
            flock($lock, \LOCK_UN);
            fclose($lock);
        }

        return 0;
    });
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
