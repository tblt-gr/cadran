<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

use App\Module\Foundation\Application\InvalidAmountInput;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\Translation\TranslatorInterface;

// Below the framework's logging listener (priority 0): setting a response stops propagation, so a
// listener at the same priority registered first would swallow every unhandled error unlogged.
#[AsEventListener(event: KernelEvents::EXCEPTION, priority: -10)]
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
        if ($throwable instanceof InvalidAmountInput) {
            $event->setResponse(ApiProblem::response(
                Response::HTTP_UNPROCESSABLE_ENTITY,
                $this->translator->trans('api.problem.invalid_amount.title'),
                $this->translator->trans('api.problem.invalid_amount.detail'),
                '/problems/'.$throwable->ruleCode,
                extensions: ['pointer' => $throwable->pointer],
            ));

            return;
        }

        $status = $throwable instanceof HttpExceptionInterface ? $throwable->getStatusCode() : 500;
        $translationKey = self::translationKey($status);

        $event->setResponse(ApiProblem::response(
            $status,
            $this->translator->trans($translationKey.'.title'),
            $this->translator->trans($translationKey.'.detail'),
            headers: self::forwardedHeaders($throwable),
        ));
    }

    /**
     * Only the delay of a 429 reaches the client: other exception headers are not meant to be public.
     *
     * @return array<string, string>
     */
    private static function forwardedHeaders(\Throwable $throwable): array
    {
        if (!$throwable instanceof HttpExceptionInterface || Response::HTTP_TOO_MANY_REQUESTS !== $throwable->getStatusCode()) {
            return [];
        }

        $retryAfter = $throwable->getHeaders()['Retry-After'] ?? null;

        return \is_scalar($retryAfter) ? ['Retry-After' => (string) $retryAfter] : [];
    }

    private static function translationKey(int $status): string
    {
        return match ($status) {
            Response::HTTP_BAD_REQUEST => 'api.problem.invalid_request',
            Response::HTTP_UNAUTHORIZED => 'api.problem.unauthorized',
            Response::HTTP_NOT_FOUND => 'api.problem.not_found',
            Response::HTTP_METHOD_NOT_ALLOWED => 'api.problem.method_not_allowed',
            Response::HTTP_FORBIDDEN => 'api.problem.forbidden',
            Response::HTTP_NOT_ACCEPTABLE => 'api.problem.not_acceptable',
            Response::HTTP_CONFLICT => 'api.problem.conflict',
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE => 'api.problem.payload_too_large',
            Response::HTTP_UNSUPPORTED_MEDIA_TYPE => 'api.problem.unsupported_media_type',
            Response::HTTP_UNPROCESSABLE_ENTITY => 'api.problem.unprocessable',
            Response::HTTP_TOO_MANY_REQUESTS => 'api.problem.too_many_requests',
            default => $status < Response::HTTP_INTERNAL_SERVER_ERROR ? 'api.problem.client_error' : 'api.problem.internal_error',
        };
    }
}
