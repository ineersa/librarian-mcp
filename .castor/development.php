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

function stackNetwork(): string
{
    return 'librarian-'.checkoutId().'-net';
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

/** @return list<string> */
function stackCommand(string $mode): array
{
    return ['docker', 'compose', '--project-name', stackProject($mode), '-f', 'compose.yaml', '-f', 'compose.override.yaml', ...('browser' === $mode ? ['-f', 'compose.browser.yaml'] : [])];
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

    return [
        'LIBRARIAN_CHECKOUT_ID' => checkoutId(),
        'LIBRARIAN_PROJECT' => stackProject($mode),
        'LIBRARIAN_NETWORK' => stackNetwork(),
        'LIBRARIAN_PUBLIC_URL' => $url,
        'HTTP_PORT' => 'browser' === $mode ? browserPublishedPort() : developmentPublishedPort(),
        'MERCURE_JWT_SECRET' => $jwt,
        'MERCURE_PUBLISHER_JWT_KEY' => $jwt,
        'MERCURE_SUBSCRIBER_JWT_KEY' => $jwt,
        'MERCURE_CORS_ORIGINS' => $url,
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

function ensureStackNetwork(): void
{
    $network = stackNetwork();
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
                foreach (['/var/browser-live', '/var/browser-live/cache', '/var/browser-live/log', '/var/browser-live/libraries'] as $relative) {
                    $path = \dirname(__DIR__).$relative;
                    if (!is_dir($path)) {
                        mkdir($path, 0700, true);
                    }
                }
            }
            ensureStackNetwork();
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

    return report('browser:live:test', ['env', 'PLAYWRIGHT_HEADLESS='.($headed ? 'false' : 'true'), 'LIBRARIAN_BROWSER_URL='.stackUrl('browser'), 'php', 'vendor/bin/phpunit', ...$arguments, '--fail-on-empty-test-suite', '--log-junit', 'var/qa/browser-live-test/junit.xml'], headed: $headed, networked: true);
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

#[AsTask(namespace: 'browser', name: 'stack:restart-worker', description: 'Restart only this checkout\'s isolated browser worker to load changed code.')]
function browserStackRestartWorker(bool $force = false): int
{
    $command = ['restart', 'worker'];

    return report('browser:stack:restart-worker', [...stackCommand('browser'), ...$command], runtime: false, operation: static function () use ($command, $force): int {
        if ($force) {
            stackRun('browser', ['kill', '--signal', 'SIGKILL', 'worker']);
        }
        stackRun('browser', $command);

        return 0;
    });
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
