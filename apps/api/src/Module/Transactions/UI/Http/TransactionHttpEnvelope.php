<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Transactions\Application\IdempotencyConflict;
use App\Module\Transactions\Application\IdempotencyRequest;
use App\Module\Transactions\Application\InvalidIdempotencyKey;
use App\Module\Transactions\Application\InvalidRefundRule;
use App\Module\Transactions\Application\InvalidSplitsInput;
use App\Module\Transactions\Application\InvalidTransferRule;
use App\Module\Transactions\Application\RefundConflict;
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

    /**
     * @param array<string, mixed>  $data
     * @param array<string, string> $headers
     */
    public function json(array $data, int $status = Response::HTTP_OK, array $headers = []): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store', ...$headers]);
    }

    public function idempotency(Request $request, bool $required, bool $emptyBodyIsObject = false): ?IdempotencyRequest
    {
        $key = $request->headers->get('Idempotency-Key');
        if (null === $key || '' === $key) {
            if ($required) {
                throw new InvalidIdempotencyKey(InvalidIdempotencyKey::REQUIRED);
            }

            return null;
        }
        if (1 !== preg_match('/^[A-Za-z0-9_.:-]{16,255}$/D', $key)) {
            throw new InvalidIdempotencyKey(InvalidIdempotencyKey::INVALID);
        }

        return new IdempotencyRequest(
            $key,
            hash('sha256', $request->getMethod()."\n".$request->getPathInfo()."\n".self::canonicalJson(
                '' === $request->getContent() && $emptyBodyIsObject ? new \stdClass() : self::decodeJson($request->getContent()),
            )),
        );
    }

    public function hasJsonObjectBody(Request $request): bool
    {
        try {
            return self::decodeJson($request->getContent()) instanceof \stdClass;
        } catch (\JsonException) {
            return false;
        }
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

    /** @param array<string, mixed> $extensions */
    public function problemWithExtensions(int $status, string $translationKey, array $extensions, string $type = ApiProblem::TYPE_BLANK): JsonResponse
    {
        return ApiProblem::response(
            $status,
            $this->translator->trans($translationKey.'.title'),
            $this->translator->trans($translationKey.'.detail'),
            $type,
            extensions: $extensions,
        );
    }

    /**
     * The rule code both selects the translated title and detail — one entry
     * per rule, `api.problem.invalid_transfer_<rule>` — and names the RFC 9457
     * problem type, mirroring {@see invalidSplitsProblem()}.
     */
    public function invalidTransferRuleProblem(InvalidTransferRule $exception): JsonResponse
    {
        $key = 'api.problem.invalid_transfer_'.str_replace('.', '_', $exception->ruleCode);

        return ApiProblem::response(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->translator->trans($key.'.title'),
            $this->translator->trans($key.'.detail'),
            '/problems/'.$exception->ruleCode,
        );
    }

    public function invalidRefundRuleProblem(InvalidRefundRule $exception): JsonResponse
    {
        $key = 'api.problem.invalid_refund_'.str_replace('.', '_', $exception->ruleCode);

        return ApiProblem::response(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->translator->trans($key.'.title'),
            $this->translator->trans($key.'.detail'),
            '/problems/refund.'.$exception->ruleCode,
        );
    }

    public function refundConflictProblem(RefundConflict $exception): JsonResponse
    {
        $key = 'api.problem.refund_'.$exception->ruleCode;

        return ApiProblem::response(
            Response::HTTP_CONFLICT,
            $this->translator->trans($key.'.title'),
            $this->translator->trans($key.'.detail', ['%remaining%' => $exception->remaining->value->toString()]),
            '/problems/refund.'.$exception->ruleCode,
            extensions: ['remaining' => [
                'value' => $exception->remaining->value->toString(),
                'assetCode' => $exception->remaining->asset->toString(),
            ]],
        );
    }

    public function idempotencyProblem(InvalidIdempotencyKey|IdempotencyConflict $exception): JsonResponse
    {
        $rule = $exception->ruleCode;
        $key = 'api.problem.idempotency_'.$rule;
        $headers = IdempotencyConflict::IN_FLIGHT === $rule ? ['Retry-After' => '1'] : [];

        return ApiProblem::response(
            IdempotencyConflict::class === $exception::class ? Response::HTTP_CONFLICT : Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->translator->trans($key.'.title'),
            $this->translator->trans($key.'.detail'),
            '/problems/idempotency.'.$rule,
            $headers,
        );
    }

    /**
     * The rule code both selects the translated title and detail — one entry
     * per rule, `api.problem.invalid_splits_<rule>` — and names the RFC 9457
     * problem type, so a new split rule needs no controller change.
     *
     * Every rule code carries the fixed `splits.` prefix (never a second dot),
     * so it is stripped rather than replaced: replacing it would double up
     * with the `invalid_splits_` translation prefix and silently miss every
     * catalogue entry, which the `type` field alone was not enough to catch.
     */
    public function invalidSplitsProblem(InvalidSplitsInput $exception): JsonResponse
    {
        $key = 'api.problem.invalid_splits_'.substr($exception->ruleCode, strlen('splits.'));

        return ApiProblem::response(
            Response::HTTP_UNPROCESSABLE_ENTITY,
            $this->translator->trans($key.'.title'),
            $this->translator->trans($key.'.detail', $exception->parameters),
            '/problems/'.$exception->ruleCode,
        );
    }

    private static function canonicalJson(mixed $value): string
    {
        return json_encode(self::canonicalValue($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function decodeJson(string $content): mixed
    {
        return json_decode($content, false, 512, JSON_THROW_ON_ERROR);
    }

    private static function canonicalValue(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            /** @var array<string, mixed> $properties */
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            $canonical = new \stdClass();
            foreach ($properties as $key => $property) {
                $canonical->{$key} = self::canonicalValue($property);
            }

            return $canonical;
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $value[$key] = self::canonicalValue($child);
            }
        }

        return $value;
    }
}
