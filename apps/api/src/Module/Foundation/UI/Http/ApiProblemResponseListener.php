<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class ApiProblemResponseListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $throwable = $event->getThrowable();
        $status = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : 500;

        $event->setResponse(new JsonResponse(
            data: [
                'type' => 'about:blank',
                'title' => Response::$statusTexts[$status] ?? 'Error',
                'status' => $status,
                'detail' => self::safeDetail($status),
            ],
            status: $status,
            headers: [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'application/problem+json',
            ],
        ));
    }

    private static function safeDetail(int $status): string
    {
        return match ($status) {
            404 => 'The requested API resource was not found.',
            405 => 'The HTTP method is not allowed for this API resource.',
            default => 'The API could not process the request.',
        };
    }
}
