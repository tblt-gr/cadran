<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Transactions\Application\InvalidSplitsInput;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TransactionHttpEnvelope
{
    public const string TYPE_STALE_VERSION = '/problems/stale-version';
    public const string TYPE_CONFLICT = '/problems/transaction-conflict';
    private const int MAX_BODY_BYTES = 32_768;

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
            return $this->problem(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'api.problem.transaction_payload_too_large');
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

    public function problem(int $status, string $translationKey, string $type = ApiProblem::TYPE_BLANK): JsonResponse
    {
        return ApiProblem::response(
            $status,
            $this->translator->trans($translationKey.'.title'),
            $this->translator->trans($translationKey.'.detail'),
            $type,
        );
    }

    /**
     * The rule code both selects the translated title and detail — one entry
     * per rule, `api.problem.invalid_splits_<rule>` — and names the RFC 9457
     * problem type, so a new split rule needs no controller change.
     */
    public function invalidSplitsProblem(InvalidSplitsInput $exception): JsonResponse
    {
        $key = 'api.problem.invalid_splits_'.str_replace('.', '_', $exception->ruleCode);

        return ApiProblem::response(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->translator->trans($key.'.title'),
            $this->translator->trans($key.'.detail', $exception->parameters),
            '/problems/'.$exception->ruleCode,
        );
    }
}
