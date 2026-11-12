<?php

declare(strict_types=1);

// User's service configuration file
// This file is loaded into the Symfony DI container

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $container): void {
    $container->parameters()
        // Public invocation is the curated Castor surface in AGENT_INSTRUCTIONS.md.
        // mate:discover preserves it instead of publishing generic CLI examples.
        // The PHP version this project runs on. Mate refuses to start under a different
        // major.minor, because it would then report on a runtime that is not your application's
        // and extensions may behave differently. Set to null to disable the check.
        ->set('mate.php_version', '8.5')
        ->set('ai_mate_symfony.profiler_dir', [
            'dev' => '%mate.root_dir%/var/cache/dev/profiler',
            'test' => '%mate.root_dir%/var/cache/test/profiler',
        ])
        ->set('ai_mate_symfony.cache_dir', [
            'dev' => '%mate.root_dir%/var/cache/dev',
            'test' => '%mate.root_dir%/var/cache/test',
        ])
    ;

    $container->services()
        ->defaults()
            ->autowire()
            ->autoconfigure()
    ;
};
