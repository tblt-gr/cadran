<?php

declare(strict_types=1);

namespace App\Module\Foundation\Infrastructure\Logging;

use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;
use Symfony\Component\DependencyInjection\Exception\RuntimeException;

/**
 * Resolves `%env(log_level:LOG_LEVEL)%` and refuses anything but the five supported levels, so a typo cannot
 * silently drop every log line or, worse, keep a debug level nobody asked for.
 */
final class LogLevelEnvProcessor implements EnvVarProcessorInterface
{
    public const array LEVELS = ['debug', 'info', 'notice', 'warning', 'error'];

    public function getEnv(string $prefix, string $name, \Closure $getEnv): string
    {
        $value = $getEnv($name);
        if (!\is_string($value) || !\in_array($value, self::LEVELS, true)) {
            throw new RuntimeException(\sprintf('The environment variable "%s" must be one of: %s.', $name, implode(', ', self::LEVELS)));
        }

        return $value;
    }

    public static function getProvidedTypes(): array
    {
        return ['log_level' => 'string'];
    }
}
