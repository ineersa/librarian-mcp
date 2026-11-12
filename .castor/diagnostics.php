<?php

declare(strict_types=1);

namespace AppTasks;

use Castor\Attribute\AsTask;

#[AsTask(namespace: 'mate', name: 'call', description: 'Call a selected official tool using its native --json parameter object; inspect its schema first.')]
function mateCall(string $tool, string $json = '{}'): int
{
    return report('mate:call', ['vendor/bin/castor', 'mate:call', $tool, '--json='.$json], runtime: false, operation: static fn (): int => callMateTool($tool, $json));
}

function callMateTool(string $tool, string $json): int
{
    $tools = ['server-info', 'monolog-search', 'monolog-context-search', 'monolog-tail', 'monolog-list-files', 'monolog-list-channels', 'symfony-services', 'symfony-service-detail', 'symfony-profiler-list', 'symfony-profiler-get', 'symfony-dotenv-check'];
    if (!\in_array($tool, $tools, true)) {
        throw new \InvalidArgumentException('Only the installed selected official tools are supported. Use mate:tools.');
    }
    $decoded = json_decode($json, flags: \JSON_THROW_ON_ERROR);
    if (!$decoded instanceof \stdClass) {
        throw new \InvalidArgumentException('--json must be a parameter object.');
    }
    $parameters = (array) $decoded;
    $maximum = match ($tool) {
        'symfony-services' => 10,
        'symfony-profiler-list' => 5,
        'symfony-dotenv-check' => 200,
        'monolog-search', 'monolog-context-search', 'monolog-tail' => 20,
        default => null,
    };
    if (null !== $maximum) {
        $limit = $parameters['limit'] ?? $maximum;
        if (!\is_int($limit) || $limit < 1 || $limit > $maximum) {
            throw new \InvalidArgumentException('limit must be an integer between 1 and '.$maximum.'. Narrow the upstream filters instead.');
        }
        $parameters['limit'] = $limit;
    }
    $artifact = '';
    if (str_starts_with($tool, 'monolog-')) {
        $environment = $parameters['environment'] ?? '*';
        if (!\is_string($environment) || ('*' !== $environment && 1 !== preg_match('/^[a-zA-Z0-9_-]+$/D', $environment))) {
            throw new \InvalidArgumentException('Invalid log environment.');
        }
        $artifact = 'var/log/'.$environment.'*.log';
    } elseif (\in_array($tool, ['symfony-services', 'symfony-service-detail'], true)) {
        $artifact = 'var/cache/*/*Container.xml';
    } elseif ('symfony-profiler-list' === $tool) {
        $context = $parameters['context'] ?? 'dev';
        if (!\in_array($context, ['dev', 'test'], true)) {
            throw new \InvalidArgumentException('Profiler context must be dev or test.');
        }
        $parameters['context'] = $context;
        $artifact = 'var/cache/'.$context.'/profiler/*/*/*';
    } elseif ('symfony-profiler-get' === $tool) {
        $token = $parameters['token'] ?? '';
        if (!\is_string($token) || 1 !== preg_match('/^[a-f0-9]{6}$/D', $token)) {
            throw new \InvalidArgumentException('A six-character profiler token is required.');
        }
        $artifact = 'var/cache/*/profiler/*/*/'.$token;
    }

    return report('mate:tool', ['php', 'vendor/bin/mate', 'tools:call', $tool, '--json='.json_encode((object) $parameters, \JSON_THROW_ON_ERROR), '--format=json'], artifact: $artifact);
}

#[AsTask(namespace: 'mate', name: 'collector', description: 'Read one official profiler collector, not a full profile or arbitrary resource URI.')]
function mateCollector(string $token, string $collector): int
{
    return report('mate:collector', ['vendor/bin/castor', 'mate:collector', $token, $collector], runtime: false, operation: static function () use ($token, $collector): int {
        if (1 !== preg_match('/^[a-f0-9]{6}$/D', $token) || 1 !== preg_match('/^[a-zA-Z0-9_.-]+$/D', $collector)) {
            throw new \InvalidArgumentException('Use a profiler token and one collector name returned by symfony-profiler-get.');
        }

        return report('mate:collector:read', ['php', 'vendor/bin/mate', 'resources:read', 'symfony-profiler://profile/'.$token.'/'.$collector, '--format=json'], artifact: 'var/cache/*/profiler/*/*/'.$token);
    });
}

#[AsTask(namespace: 'mate', name: 'tools', description: 'Discover installed Mate tool schemas as JSON.')]
function mateTools(): int
{
    return report('mate:tools', ['php', 'vendor/bin/mate', 'tools:list', '--format=json']);
}

#[AsTask(namespace: 'mate', name: 'inspect', description: 'Inspect one Mate tool schema as JSON.')]
function mateInspect(string $tool): int
{
    return report('mate:inspect', ['php', 'vendor/bin/mate', 'tools:inspect', $tool, '--format=json']);
}

#[AsTask(namespace: 'mate', name: 'discover', description: 'Refresh official extensions while preserving curated project instructions.')]
function mateDiscover(): int
{
    $root = \dirname(__DIR__);
    $instructions = [];
    foreach (['AGENTS.md', 'mate/AGENT_INSTRUCTIONS.md', 'CLAUDE.md'] as $path) {
        $instructions[$path] = is_file($root.'/'.$path) ? file_get_contents($root.'/'.$path) : false;
    }
    try {
        return report('mate:discover', ['php', 'vendor/bin/mate', 'discover', '--no-interaction']);
    } finally {
        foreach ($instructions as $path => $content) {
            if (false !== $content) {
                file_put_contents($root.'/'.$path, $content);
            } elseif (is_file($root.'/'.$path)) {
                unlink($root.'/'.$path);
            }
        }
    }
}
