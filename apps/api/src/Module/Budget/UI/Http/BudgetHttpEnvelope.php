<?php

declare(strict_types=1);

namespace App\Module\Budget\UI\Http;

use App\Module\Foundation\UI\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The request and response envelope shared by the budget endpoints: the media
 * type, the size ceiling, the JSON syntax, the identifier shape and the
 * RFC 9457 document — kept together rather than copied into every controller,
 * exactly as {@see \App\Module\Categories\UI\Http\CategoryHttpEnvelope} does
 * for categories.
 */
final readonly class BudgetHttpEnvelope
{
    private const int MAX_BODY_BYTES = 16_384;

    public function __construct(private TranslatorInterface $translator)
    {
    }

    /** @return array<mixed>|Response */
    public function body(Request $request): array|Response
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->problem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'api.problem.unsupported_media_type');
        }
        if (mb_strlen($request->getContent(), '8bit') > self::MAX_BODY_BYTES) {
            return $this->problem(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'api.problem.budget_payload_too_large');
        }

        try {
            return $request->toArray();
        } catch (\Throwable) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_request');
        }
    }

    public function isIdentifier(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value);
    }

    /** @param array<string, mixed> $data */
    public function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }

    /** @param array<string, mixed> $extensions */
    public function problem(
        int $status,
        string $translationKey,
        string $type = ApiProblem::TYPE_BLANK,
        array $extensions = [],
    ): JsonResponse {
        return ApiProblem::response(
            $status,
            $this->translator->trans($translationKey.'.title'),
            $this->translator->trans($translationKey.'.detail'),
            $type,
            extensions: $extensions,
        );
    }
}
