<?php

declare(strict_types=1);

namespace App\Module\Foundation\Infrastructure\Logging;

use App\Module\Foundation\Application\InvalidAmountInput;
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

        $context = SafeExceptionContext::of($exception);
        $text = \sprintf('Uncaught PHP Exception %s at %s', $context['exception_class'], $context['location']);
        if (isset($context['sqlstate'])) {
            $text .= \sprintf(' (SQLSTATE %s)', $context['sqlstate']);
        }

        // InvalidAmountInput is answered 422 by ApiProblemResponseListener: a client error that is no HTTP exception.
        $clientError = $exception instanceof InvalidAmountInput
            || ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500);
        $logger->log($clientError ? LogLevel::INFO : ($logLevel ?? LogLevel::CRITICAL), $text, $context);
    }
}
