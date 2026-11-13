<?php

declare(strict_types=1);

namespace AppTasks;

use Castor\Attribute\AsArgsAfterOptionEnd;
use Castor\Attribute\AsTask;
use Symfony\Component\Process\Process;

/**
 * @param list<string>           $command
 * @param (\Closure(): int)|null $operation
 */
function report(string $task, array $command, bool $runtime = true, bool $headed = false, ?\Closure $operation = null, string $artifact = '', bool $networked = false, bool $hostNetwork = false): int
{
    $root = \dirname(__DIR__);
    $relative = 'var/qa/'.str_replace(':', '-', $task);
    $directory = $root.'/'.$relative;
    if (!is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
    $lock = fopen($directory.'/lock', 'cb');
    if (false === $lock || !flock($lock, \LOCK_EX | \LOCK_NB)) {
        throw new \RuntimeException('Task report is already locked: '.$task);
    }
    $started = microtime(true);
    $stdout = \in_array($task, ['cs:fix', 'cs:check', 'phpstan', 'lsp:check', 'mate:tool', 'mate:collector:read', 'mate:tools', 'mate:inspect'], true) ? 'stdout.json' : 'stdout.log';
    $result = ['task' => $task, 'command' => $command, 'started_at' => gmdate(\DATE_ATOM), 'status' => 'running', 'exit_code' => null, 'stdout' => $relative.'/'.$stdout, 'stderr' => $relative.'/stderr.log'];
    $save = static function () use (&$result, $directory): void {
        file_put_contents($directory.'/result.json', json_encode($result, \JSON_PRETTY_PRINT | \JSON_THROW_ON_ERROR)."\n");
    };
    $save();
    file_put_contents($directory.'/'.$stdout, '');
    file_put_contents($directory.'/stderr.log', '');
    $container = $runtime ? 'librarian-qa-'.bin2hex(random_bytes(8)) : null;
    $execution = new TaskExecution(TaskExecution::$current);
    TaskExecution::$current = $execution;
    $handlers = [];
    pcntl_async_signals(true);
    foreach ([\SIGINT, \SIGTERM, \SIGHUP] as $signal) {
        $handlers[$signal] = pcntl_signal_get_handler($signal);
        pcntl_signal($signal, static function (int $received) use ($execution): void {
            $execution->interrupted = 128 + $received;
            $process = $execution->process;
            if (null !== $process && $process->isRunning()) {
                $process->signal($received);
                $process->stop(10, \SIGKILL);
            }
        });
    }
    try {
        $execution->throwIfInterrupted();
        if ('' !== $artifact && [] === glob($root.'/'.$artifact)) {
            throw new \RuntimeException('Missing diagnostic artifacts. Warm the development cache or record a local request first.');
        }
        if (null !== $operation) {
            ob_start(static function (string $output) use ($directory, $stdout): string {
                file_put_contents($directory.'/'.$stdout, $output, \FILE_APPEND);

                return '';
            }, 1);
            try {
                $exit = $operation();
            } finally {
                ob_end_flush();
            }
        } else {
            if (null !== $container) {
                $command = runtimeCommand($command, $container, $headed, $networked, $hostNetwork);
                $result['command'] = $command;
                $result['runtime_container'] = $container;
                $save();
            }
            $process = new Process($command, $root, ['NO_COLOR' => '1', 'COMPOSER_NO_INTERACTION' => '1'], timeout: 'test:browser' === $task ? 300 : 600);
            $exit = runTaskProcess($process, false, static function (string $type, string $data) use ($directory, $stdout): void {
                file_put_contents($directory.'/'.(Process::ERR === $type ? 'stderr.log' : $stdout), $data, \FILE_APPEND);
            });
            if (0 === $exit && 'mate:collector:read' === $task) {
                $contents = json_decode((string) file_get_contents($directory.'/'.$stdout), true, flags: \JSON_THROW_ON_ERROR);
                foreach ($contents as $content) {
                    if (isset($content['text']['error'])) {
                        throw new \RuntimeException('Collector unavailable: '.$content['text']['error']);
                    }
                }
            }
            if (0 === $exit && 'lsp:check' === $task) {
                $diagnostics = json_decode((string) file_get_contents($directory.'/'.$stdout), true, flags: \JSON_THROW_ON_ERROR);
                if (!\is_array($diagnostics) || true !== ($diagnostics['complete'] ?? false) || [] === ($diagnostics['projects'] ?? [])) {
                    throw new \RuntimeException('LSP analysis is incomplete.');
                }
                foreach ($diagnostics['projects'] as $project) {
                    if (true !== ($project['complete'] ?? false) || 'runtime' !== ($project['analysis']['mode'] ?? null) || 'ready' !== ($project['runtime']['state'] ?? null)) {
                        throw new \RuntimeException('LSP runtime analysis is incomplete.');
                    }
                }
            }
        }
    } catch (\Throwable $error) {
        file_put_contents($directory.'/stderr.log', $error->getMessage()."\n", \FILE_APPEND);
        $exit = 1;
    } finally {
        if (null !== $container) {
            try {
                cleanupRuntime($container);
            } catch (\Throwable $error) {
                file_put_contents($directory.'/stderr.log', $error->getMessage()."\n", \FILE_APPEND);
                $exit = 1;
            }
        }
        foreach ($handlers as $signal => $handler) {
            pcntl_signal($signal, $handler);
        }
        TaskExecution::$current = $execution->parent;
    }
    if (0 !== $execution->interrupted) {
        $exit = $execution->interrupted;
    }
    $result['exit_code'] = $exit;
    $result['status'] = 0 === $exit ? 'passed' : 'failed';
    $result['finished_at'] = gmdate(\DATE_ATOM);
    $result['duration_ms'] = (microtime(true) - $started) * 1000;
    $save();
    flock($lock, \LOCK_UN);
    fclose($lock);
    echo json_encode(['task' => $task, 'status' => $result['status'], 'exit_code' => $exit, 'result' => $relative.'/result.json'], \JSON_THROW_ON_ERROR)."\n";

    return $exit;
}

#[AsTask(namespace: 'composer', name: 'validate')]
function composerValidate(): int
{
    return report('composer:validate', ['composer', 'validate', '--strict']);
}

#[AsTask(namespace: 'cs', name: 'fix')]
function csFix(): int
{
    return report('cs:fix', ['php', 'vendor/bin/php-cs-fixer', 'fix', '--format=json']);
}

#[AsTask(namespace: 'cs', name: 'check')]
function csCheck(): int
{
    return report('cs:check', ['php', 'vendor/bin/php-cs-fixer', 'fix', '--dry-run', '--diff', '--format=json']);
}

#[AsTask(namespace: '', name: 'phpstan')]
function phpstan(): int
{
    return report('phpstan', ['php', 'vendor/bin/phpstan', 'analyse', '--no-progress', '--error-format=json', '--memory-limit=512M']);
}

#[AsTask(namespace: 'lsp', name: 'check')]
function lspCheck(): int
{
    return report('lsp:check', ['symfony', 'lsp:check', '--format=json', '--environment=dev', ...(\Castor\output()->isVerbose() ? ['--verbose'] : [])]);
}

/** @param list<string> $arguments */
#[AsTask(namespace: '', name: 'test', description: 'Prepare the isolated browser stack and run PHPUnit; pass native PHPUnit arguments after --.')]
function browserTest(bool $headed = false, #[AsArgsAfterOptionEnd] array $arguments = []): int
{
    return report('test', ['vendor/bin/castor', 'test', ...($headed ? ['--headed'] : []), '--', ...$arguments], runtime: false, operation: static function () use ($headed, $arguments): int {
        foreach ([\dirname(__DIR__).'/var', \dirname(__DIR__).'/var/browser-live'] as $storage) {
            if (is_link($storage)) {
                throw new \RuntimeException('Refusing symlinked browser storage.');
            }
        }
        $started = browserStackUp();
        if (0 !== $started) {
            return $started;
        }
        $databaseUrl = postgresDatabaseUrl('browser', forHostNetwork: false);
        $hostDatabaseUrl = postgresDatabaseUrl('browser', forHostNetwork: true);
        $hostLockDsn = postgresLockDsn('browser', forHostNetwork: true);
        assertOwnedBrowserDatabaseUrl($hostDatabaseUrl);

        // Clear stale compiled containers before migrations/fixture setup.
        stackRun('browser', ['stop', 'worker']);
        stackRun('browser', ['exec', '-T', 'php', 'php', 'bin/console', 'cache:clear', '--env=test', '--no-interaction']);
        // Only this verified checkout-owned browser database is disposable.
        stackRun('browser', ['exec', '-T', '-e', 'DATABASE_URL='.$databaseUrl, 'php', 'php', 'bin/console', 'doctrine:database:drop', '--force', '--if-exists', '--env=test', '--no-interaction']);
        stackRun('browser', ['exec', '-T', '-e', 'DATABASE_URL='.$databaseUrl, 'php', 'php', 'bin/console', 'doctrine:database:create', '--env=test', '--no-interaction']);
        stackRun('browser', ['exec', '-T', '-e', 'DATABASE_URL='.$databaseUrl, 'php', 'php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction']);
        // FrankenPHP workers retain loaded classes; use current application code.
        stackRun('browser', ['restart', 'php']);
        stackRun('browser', ['up', '-d', '--wait', 'worker']);

        // Linux host networking reaches the printed loopback URL and Postgres port.
        return report('test:browser', ['env', 'DATABASE_URL='.$hostDatabaseUrl, 'LOCK_DSN='.$hostLockDsn, 'PLAYWRIGHT_HEADLESS='.($headed ? 'false' : 'true'), 'LIBRARIAN_BROWSER_URL='.stackUrl('browser'), 'php', 'vendor/bin/phpunit', ...$arguments, '--fail-on-empty-test-suite', '--log-junit', 'var/qa/test/junit.xml'], headed: $headed, hostNetwork: true);
    });
}

#[AsTask(namespace: 'app', name: 'composer', description: 'Run application Composer in the project runtime; pass its arguments after --.')]
function appComposer(#[AsArgsAfterOptionEnd] array $arguments = []): int
{
    return report('app:composer', ['composer', ...$arguments]);
}

/** @param list<string> $arguments */
#[AsTask(namespace: 'app', name: 'console', description: 'Run the Symfony application console; pass its arguments after --.')]
function appConsole(#[AsArgsAfterOptionEnd] array $arguments = [], string $isolatedDatabase = '', bool $browserDatabase = false): int
{
    if ($browserDatabase && '' !== $isolatedDatabase) {
        throw new \InvalidArgumentException('Choose either the fixed browser database or a diagnostic database.');
    }
    if ($browserDatabase || '' !== $isolatedDatabase) {
        if ($browserDatabase) {
            foreach (['var', 'var/browser-live'] as $path) {
                if (is_link(\dirname(__DIR__).'/'.$path)) {
                    throw new \RuntimeException('Refusing symlinked diagnostic storage.');
                }
            }
            $databaseUrl = postgresDatabaseUrl('browser', forHostNetwork: false);
            $lockDsn = postgresLockDsn('browser', forHostNetwork: false);

            return report('app:console', ['env', 'DATABASE_URL='.$databaseUrl, 'LOCK_DSN='.$lockDsn, 'php', 'bin/console', '--env=dev', ...$arguments]);
        }

        if (1 !== preg_match('/\A[a-z0-9-]+\z/D', $isolatedDatabase)) {
            throw new \InvalidArgumentException('Use a lowercase diagnostic database identifier.');
        }
        foreach (['var', 'var/browser'] as $path) {
            if (is_link(\dirname(__DIR__).'/'.$path)) {
                throw new \RuntimeException('Refusing symlinked diagnostic storage.');
            }
        }
        $databaseUrl = isolatedPostgresDatabaseUrl($isolatedDatabase);
        $lockDsn = isolatedPostgresLockDsn($isolatedDatabase);

        return report('app:console', ['env', 'DATABASE_URL='.$databaseUrl, 'LOCK_DSN='.$lockDsn, 'php', 'bin/console', '--env=dev', ...$arguments]);
    }

    return report('app:console', ['php', 'bin/console', ...$arguments]);
}

/** @param array<string, callable(): int> $tasks */
function aggregate(string $task, array $tasks): int
{
    return report($task, ['vendor/bin/castor', $task], runtime: false, operation: static function () use ($tasks): int {
        $exit = 0;
        foreach ($tasks as $run) {
            $code = $run();
            if (0 === $exit && 0 !== $code) {
                $exit = $code;
            }
            if (\in_array($code, [128 + \SIGINT, 128 + \SIGTERM, 128 + \SIGHUP], true)) {
                break;
            }
        }

        return $exit;
    });
}

#[AsTask(namespace: 'qa', name: 'static')]
function qaStatic(): int
{
    return aggregate('qa:static', ['composer:validate' => composerValidate(...), 'cs:check' => csCheck(...), 'phpstan' => phpstan(...), 'lsp:check' => lspCheck(...)]);
}

#[AsTask(namespace: '', name: 'check', description: 'Run static checks and headless browser tests; --headed requires an owner request.')]
function check(bool $headed = false): int
{
    return aggregate('check', ['qa:static' => qaStatic(...), 'test' => static fn (): int => browserTest($headed)]);
}
