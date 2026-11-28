<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The request and response plumbing shared by every JSON endpoint: media type,
 * size ceiling, JSON syntax, `no-store` answers and the RFC 9457 document.
 * Each caller names its own too-large translation key so the text is unchanged.
 */
final readonly class JsonRequestEnvelope
{
    public function __construct(private TranslatorInterface $translator)
    {
    }

    /**
     * @param \Closure(int, string): JsonResponse|null $refuse builds the refusal from a status and a translation key,
     *                                                         for a module whose problems carry their own type or members
     *
     * @return array<mixed>|Response
     */
    public function body(Request $request, BodyLimit $limit, string $tooLargeKey, ?\Closure $refuse = null): array|Response
    {
        $refuse ??= fn (int $status, string $translationKey): JsonResponse => $this->problem($status, $translationKey);

        if ('json' !== $request->getContentTypeFormat()) {
            return $refuse(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'api.problem.unsupported_media_type');
        }
        if (mb_strlen($request->getContent(), '8bit') > $limit->value) {
            return $refuse(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, $tooLargeKey);
        }

        try {
            return $request->toArray();
        } catch (\Throwable) {
            return $refuse(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_request');
        }
    }

    /** @param array<string, mixed> $data */
    public function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }

    /**
     * @param array<string, mixed>  $extensions RFC 9457 extension members
     * @param array<string, string> $headers    extra response headers
     * @param array<string, mixed>  $parameters translation parameters of the detail
     */
    public function problem(
        int $status,
        string $translationKey,
        string $type = ApiProblem::TYPE_BLANK,
        array $extensions = [],
        array $headers = [],
        array $parameters = [],
    ): JsonResponse {
        return ApiProblem::response(
            $status,
            $this->translator->trans($translationKey.'.title'),
            $this->translator->trans($translationKey.'.detail', $parameters),
            $type,
            $headers,
            $extensions,
        );
    }
}
