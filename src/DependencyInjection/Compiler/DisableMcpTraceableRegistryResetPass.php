<?php

declare(strict_types=1);

namespace App\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class DisableMcpTraceableRegistryResetPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('mcp.traceable_registry')) {
            return;
        }

        $container->getDefinition('mcp.traceable_registry')->clearTag('kernel.reset');
    }
}
