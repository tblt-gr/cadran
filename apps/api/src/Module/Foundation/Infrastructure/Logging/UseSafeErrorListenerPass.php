<?php

declare(strict_types=1);

namespace App\Module\Foundation\Infrastructure\Logging;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * Swaps the class of the framework's `exception_listener` and keeps its arguments, tags and priorities. An
 * app-level service definition would be overwritten when the framework extension loads, hence a pass.
 */
final class UseSafeErrorListenerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $container->getDefinition('exception_listener')->setClass(SafeErrorListener::class);
    }
}
