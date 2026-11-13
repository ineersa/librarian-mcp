<?php

declare(strict_types=1);

namespace AppTasks;

use Symfony\Component\Process\Process;

function toolingImage(): string
{
    return 'librarian-'.checkoutId().'-tooling:dev';
}

/** @param list<string> $command
 * @return list<string>
 */
function runtimeCommand(array $command, string $container, bool $headed, bool $networked = false, bool $hostNetwork = false): array
{
    $root = \dirname(__DIR__);
    if (!is_dir($root.'/var/tooling/home')) {
        mkdir($root.'/var/tooling/home', 0700, true);
    }
    if ($networked && $hostNetwork) {
        throw new \InvalidArgumentException('Choose either private stack networking or Linux host networking, not both.');
    }
    $docker = ['docker', 'run', '--rm', '--init', '--name', $container, '--label', 'librarian.qa.owner='.hash('sha256', $root), '--user', posix_getuid().':'.posix_getgid(), '--volume', $root.':/app', '--env', 'XDG_CONFIG_HOME=/app/var/tooling/home/.config', '--env', 'XDG_CACHE_HOME=/app/var/tooling/home/.cache'];
    if ($hostNetwork) {
        // Linux-only: share the host network namespace so the harness reaches the
        // printed loopback-published stack URL (127.0.0.1:PORT). Do not combine
        // with private Compose networking; Chroma remains unpublished.
        $docker = [...$docker, '--network', 'host'];
    }
    if ($networked) {
        // Join the private browser/dev stack network for chroma/php, and keep
        // host-gateway for 0.0.0.0-published host model listeners (8059/8060).
        // Loopback-published stack ports are NOT reachable via host-gateway.
        $docker = [...$docker, '--network', stackNetwork(), '--add-host', 'host.docker.internal:host-gateway'];
    }
    if ($headed) {
        $display = getenv('DISPLAY');
        if (false === $display || '' === $display || !is_dir('/tmp/.X11-unix')) {
            throw new \RuntimeException('A visible X11 DISPLAY is required. No headless fallback.');
        }
        $read = new Process(['xauth', '-i', 'nlist', $display]);
        $read->mustRun();
        if ('' === trim($read->getOutput())) {
            throw new \RuntimeException('No authority entry for the current display.');
        }
        $cookie = $root.'/var/tooling/'.$container.'.Xauthority';
        file_put_contents($cookie, '');
        chmod($cookie, 0600);
        $merge = new Process(['xauth', '-f', $cookie, 'nmerge', '-'], input: preg_replace('/^.{4}/m', 'ffff', $read->getOutput()));
        $merge->mustRun();
        $docker = [...$docker, '--volume', '/tmp/.X11-unix:/tmp/.X11-unix:ro', '--env', 'DISPLAY='.$display, '--env', 'XAUTHORITY=/app/var/tooling/'.$container.'.Xauthority'];
    }

    return [...$docker, toolingImage(), ...$command];
}

function cleanupRuntime(string $container): void
{
    $root = \dirname(__DIR__);
    try {
        $inspect = new Process(['docker', 'inspect', '--format', '{{index .Config.Labels "librarian.qa.owner"}}', $container], timeout: 15);
        if (0 !== $inspect->run()) {
            if (false === stripos($inspect->getErrorOutput(), 'no such')) {
                throw new \RuntimeException('Cannot verify owned runtime cleanup: '.$inspect->getErrorOutput());
            }

            return;
        }
        if (hash('sha256', $root) !== trim($inspect->getOutput())) {
            throw new \RuntimeException('Refusing to remove a runtime with an unexpected owner label.');
        }
        (new Process(['docker', 'rm', '--force', $container], timeout: 20))->mustRun();
    } finally {
        $cookie = $root.'/var/tooling/'.$container.'.Xauthority';
        if (is_file($cookie)) {
            unlink($cookie);
        }
    }
}
