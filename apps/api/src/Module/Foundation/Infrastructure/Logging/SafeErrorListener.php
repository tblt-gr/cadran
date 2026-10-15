<?php

declare(strict_types=1);

namespace App\Module\Foundation\Infrastructure\Logging;

use App\Module\Foundation\Application\InvalidAmountInput;
use Doctrine\DBAL\Exception\DriverException;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Symfony\Component\DependencyInjection\Attribute\Exclude;
use Symfony\Component\HttpKernel\EventListener\ErrorListener;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Logs an unhandled exception by its class, its SQLSTATE and its location only. The framework listener prints
 * the raw message, and a database error message quotes the refused row ("Failing row contains (...)"), that is
 * the amounts and labels of the financial data. The exception object is kept out of the context too, because
 * a handler that renders context would print the message again.
 *
 * A client error (4xx) is a normal answer, not an incident: it is logged at info, below the level the deployed
 * logger emits, as it was before errors were logged at all. Only a server error reaches the log.
 */
// Not a service of its own: UseSafeErrorListenerPass gives the framework's `exception_listener` this class.
#[Exclude]
final class SafeErrorListener extends ErrorListener
{
    protected function logException(\Throwable $exception, string $message, ?string $logLevel = null, ?string $logChannel = null): void
    {
        $channelLogger = null !== $logChannel ? ($this->loggers[$logChannel] ?? null) : null;
        $logger = $channelLogger instanceof LoggerInterface ? $channelLogger : $this->logger;
        if (null === $logger) {
            return;
        }

        $location = basename($exception->getFile()).':'.$exception->getLine();
        $sqlState = self::sqlState($exception);
        $context = ['exception_class' => $exception::class, 'location' => $location];
        $text = \sprintf('Uncaught PHP Exception %s at %s', $exception::class, $location);
        if (null !== $sqlState) {
            $context['sqlstate'] = $sqlState;
            $text .= \sprintf(' (SQLSTATE %s)', $sqlState);
        }

        // InvalidAmountInput is answered 422 by ApiProblemResponseListener: a client error that is no HTTP exception.
        $clientError = $exception instanceof InvalidAmountInput
            || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500);
        $logger->log($clientError ? LogLevel::INFO : ($logLevel ?? LogLevel::CRITICAL), $text, $context);
    }

    private static function sqlState(\Throwable $exception): ?string
    {
        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            if ($current instanceof DriverException) {
                return $current->getSQLState();
            }
        }

        return null;
    }
}
