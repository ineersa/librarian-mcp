<?php

declare(strict_types=1);

namespace prod;

use Castor\Attribute\AsTask;
use function CastorTasks\ensure_data_dir;
use function CastorTasks\prod_compose;
use function CastorTasks\prod_php_exec;

#[AsTask(description: 'Build production images')]
function build(): void
{
    prod_compose('build');
}

#[AsTask(description: 'Start production stack locally')]
function up(): void
{
    ensure_data_dir();
    prod_compose('up -d --build');
}

#[AsTask(description: 'Stop production stack')]
function down(): void
{
    prod_compose('down');
}

#[AsTask(description: 'Stop production services without removing containers')]
function stop(): void
{
    prod_compose('stop');
}

#[AsTask(description: 'Restart production stack')]
function restart(): void
{
    prod_compose('restart');
}

#[AsTask(description: 'Show production service status')]
function ps(): void
{
    prod_compose('ps');
}

#[AsTask(description: 'Stream all production logs')]
function logs(): void
{
    prod_compose('logs -f');
}

#[AsTask(description: 'Run Symfony console in production compose')]
function console(string $cmd): void
{
    prod_php_exec('php bin/console ' . $cmd);
}

#[AsTask(description: 'Validate production compose configuration')]
function config(): void
{
    prod_compose('config');
}
