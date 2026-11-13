<?php

declare(strict_types=1);

namespace AppTasks;

use Castor\Attribute\AsArgsAfterOptionEnd;
use Castor\Attribute\AsTask;

#[AsTask(namespace: 'tooling', name: 'build', description: 'Build the authoritative development and QA runtime.')]
function toolingBuild(): int
{
    return report('tooling:build', ['docker', 'build', '--target', 'frankenphp_tooling', '-t', toolingImage(), '.'], runtime: false);
}

/** @param list<string> $arguments */
#[AsTask(namespace: 'composer', name: 'install', description: 'Install locked dependencies; pass additional native Composer options after --.')]
function composerInstall(#[AsArgsAfterOptionEnd] array $arguments = []): int
{
    return report('composer:install', ['composer', 'install', '--no-interaction', '--no-progress', ...$arguments]);
}

/** @param list<string> $arguments */
#[AsTask(namespace: 'composer', name: 'require', description: 'Require dependencies through the authoritative runtime; pass native Composer arguments after --.')]
function composerRequire(#[AsArgsAfterOptionEnd] array $arguments = []): int
{
    return report('composer:require', ['composer', 'require', '--no-interaction', '--no-progress', ...$arguments]);
}

#[AsTask(namespace: 'browser', name: 'install', description: 'Install the pinned Chromium browser without launching it.')]
function browserInstall(): int
{
    return report('browser:install', ['php', 'vendor/bin/playwright-install', 'chromium']);
}

#[AsTask(namespace: 'tooling', name: 'prepare', description: 'Build runtime, install dependencies, and prepare Chromium.')]
function toolingPrepare(): int
{
    return aggregate('tooling:prepare', ['tooling:build' => toolingBuild(...), 'composer:install' => composerInstall(...), 'browser:install' => browserInstall(...)]);
}

/** @param list<string> $arguments */
#[AsTask(namespace: 'tooling', name: 'run', description: 'Run native diagnostic argv in the authoritative tooling image; optionally join the owned browser network.')]
function toolingRun(#[AsArgsAfterOptionEnd] array $arguments = [], bool $networked = false): int
{
    if ([] === $arguments) {
        throw new \InvalidArgumentException('Pass the native command and its arguments after --.');
    }

    return report('tooling:run', $arguments, networked: $networked);
}

#[AsTask(namespace: 'dev', name: 'cache:warmup', description: 'Prepare compiled container metadata for Mate.')]
function devCacheWarmup(): int
{
    return report('dev:cache:warmup', ['php', 'bin/console', 'cache:warmup', '--env=dev', '--no-interaction']);
}

#[AsTask(namespace: 'test', name: 'database:fresh', description: 'Verify migrations and mappings on new, isolated diagnostic storage without resetting existing databases.')]
function testDatabaseFresh(): int
{
    $root = \dirname(__DIR__);
    foreach ([$root.'/var', $root.'/var/browser'] as $path) {
        if (is_link($path)) {
            throw new \RuntimeException('Refusing symlinked diagnostic storage.');
        }
    }
    $relative = 'var/browser/migrations-'.bin2hex(random_bytes(8));
    mkdir($root.'/'.$relative, 0700, true);
    $command = ['env', 'DATABASE_URL=sqlite:////app/'.$relative.'/application.db', 'php', 'bin/console'];
    $exit = report('test:database:fresh-migrate', [...$command, 'doctrine:migrations:migrate', '--env=dev', '--no-interaction']);
    if (0 !== $exit) {
        return $exit;
    }

    return report('test:database:fresh-schema', [...$command, 'doctrine:schema:validate', '--env=dev']);
}
