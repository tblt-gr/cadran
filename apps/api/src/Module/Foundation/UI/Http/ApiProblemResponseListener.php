<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

#[AsEventListener(event: KernelEvents::EXCEPTION)]
final class ApiProblemResponseListener
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function __invoke(ExceptionEvent $event): void
    {
        if (!str_starts_with($event->getRequest()->getPathInfo(), '/api/')) {
            return;
        }

        $throwable = $event->getThrowable();
        $status = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : 500;
        $translationKey = self::translationKey($status);

        $event->setResponse(ApiProblem::response(
            $status,
            $this->translator->trans($translationKey.'.title'),
            $this->translator->trans($translationKey.'.detail'),
        ));
    }

    private static function translationKey(int $status): string
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => 'api.problem.invalid_request',
            Response::HTTP_UNAUTHORIZED => 'api.problem.unauthorized',
            Response::HTTP_NOT_FOUND => 'api.problem.not_found',
            Response::HTTP_METHOD_NOT_ALLOWED => 'api.problem.method_not_allowed',
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE => 'api.problem.unsupported_media_type',
            default => 'api.problem.internal_error',
        };
    }
}
