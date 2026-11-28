<?php

declare(strict_types=1);

namespace App\Module\Foundation\Infrastructure\Logging;

use Doctrine\DBAL\Exception\DriverException;

/**
 * What may be logged about a failure: never its message, which can quote financial data, nor the exception
 * object, which a handler rendering context would print with its message.
 */
final class SafeExceptionContext
{
    /** @return array{exception_class: class-string, location: string, sqlstate?: string} */
    public static function of(\Throwable $exception): array
    {
        $context = [
            'exception_class' => $exception::class,
            'location' => basename($exception->getFile()).':'.$exception->getLine(),
        ];

        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof DriverException) {
                $sqlState = $current->getSQLState();
                if (null !== $sqlState) {
                    $context['sqlstate'] = $sqlState;
                }
                break;
            }
        }

        return $context;
    }
}
