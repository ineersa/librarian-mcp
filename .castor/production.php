<?php

declare(strict_types=1);

namespace AppTasks;

use Castor\Attribute\AsArgsAfterOptionEnd;
use Castor\Attribute\AsTask;
use Symfony\Component\Process\Process;

/** @return list<string> */
function productionComposeCommand(): array
{
    // Compose interpolates ${POSTGRES_PASSWORD} etc. from --env-file values.
    // Optional .env.prod.local may override those for host-side interpolation
    // without printing or exporting secrets into the Castor process.
    $command = ['docker', 'compose', '--env-file', '.env', '--env-file', '.env.prod'];
    if (is_file(\dirname(__DIR__).'/.env.prod.local')) {
        $command = [...$command, '--env-file', '.env.prod.local'];
    }

    return [...$command, '-f', 'compose.yaml', '-f', 'compose.prod.yaml', ...(is_file(\dirname(__DIR__).'/compose.sources.yaml') ? ['-f', 'compose.sources.yaml'] : [])];
}

/** @param list<string> $arguments */
function productionRun(array $arguments): void
{
    $process = new Process([...productionComposeCommand(), ...$arguments], \dirname(__DIR__), timeout: 600);
    runTaskProcess($process);
    echo $process->getOutput();
    echo $process->getErrorOutput();
}

#[AsTask(namespace: 'prod', name: 'build', description: 'Build production images for this checkout.')]
function productionBuild(): int
{
    return report('prod:build', [...productionComposeCommand(), 'build'], runtime: false, operation: static function (): int {
        productionRun(['build']);

        return 0;
    });
}

#[AsTask(namespace: 'prod', name: 'up', description: 'Start the production-like stack while preserving data-prod volumes.')]
function productionUp(): int
{
    return report('prod:up', [...productionComposeCommand(), 'up'], runtime: false, operation: static function (): int {
        productionRun(['up', '-d', '--build']);

        return 0;
    });
}

#[AsTask(namespace: 'prod', name: 'down', description: 'Stop the production-like stack without deleting volumes.')]
function productionDown(): int
{
    return report('prod:down', [...productionComposeCommand(), 'down'], runtime: false, operation: static function (): int {
        productionRun(['down']);

        return 0;
    });
}

#[AsTask(namespace: 'prod', name: 'stop', description: 'Stop production services without removing containers.')]
function productionStop(): int
{
    return report('prod:stop', [...productionComposeCommand(), 'stop'], runtime: false, operation: static function (): int {
        productionRun(['stop']);

        return 0;
    });
}

#[AsTask(namespace: 'prod', name: 'restart', description: 'Restart the production-like stack.')]
function productionRestart(): int
{
    return report('prod:restart', [...productionComposeCommand(), 'restart'], runtime: false, operation: static function (): int {
        productionRun(['restart']);

        return 0;
    });
}

#[AsTask(namespace: 'prod', name: 'ps', description: 'Show production service status.')]
function productionPs(): int
{
    return report('prod:ps', [...productionComposeCommand(), 'ps'], runtime: false, operation: static function (): int {
        productionRun(['ps']);

        return 0;
    });
}

#[AsTask(namespace: 'prod', name: 'logs', description: 'Stream production logs.')]
function productionLogs(): int
{
    return report('prod:logs', [...productionComposeCommand(), 'logs'], runtime: false, operation: static function (): int {
        productionRun(['logs', '-f']);

        return 0;
    });
}

/** @param list<string> $arguments */
#[AsTask(namespace: 'prod', name: 'console', description: 'Run Symfony console in the production-like stack; pass arguments after --.')]
function productionConsole(#[AsArgsAfterOptionEnd] array $arguments = []): int
{
    $command = ['exec', '-T', 'php', 'php', 'bin/console', ...$arguments];

    return report('prod:console', [...productionComposeCommand(), ...$command], runtime: false, operation: static function () use ($command): int {
        productionRun($command);

        return 0;
    });
}

#[AsTask(namespace: 'prod', name: 'config', description: 'Validate production compose configuration.')]
function productionConfig(): int
{
    return report('prod:config', [...productionComposeCommand(), 'config'], runtime: false, operation: static function (): int {
        productionRun(['config']);

        return 0;
    });
}
