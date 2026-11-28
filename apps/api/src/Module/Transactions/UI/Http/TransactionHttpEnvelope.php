<?php

declare(strict_types=1);

namespace App\Module\Transactions\UI\Http;

use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Transactions\Application\IdempotencyRequest;
use App\Module\Transactions\Application\InvalidIdempotencyKey;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

final readonly class TransactionHttpEnvelope
{
    public const string TYPE_STALE_VERSION = '/problems/stale-version';
    public const string TYPE_CONFLICT = '/problems/transaction-conflict';

    public function __construct(private JsonRequestEnvelope $envelope, private TranslatorInterface $translator)
    {
    }

    /** @return array<mixed>|Response */
    public function readBody(Request $request): array|Response
    {
        return $this->envelope->body($request, BodyLimit::Large, 'api.problem.transaction_payload_too_large');
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

    /** @param array<string, string> $headers */
    public function problem(int $status, string $translationKey, string $type = ApiProblem::TYPE_BLANK, array $headers = []): JsonResponse
    {
        return ApiProblem::response(
            $status,
            $this->translator->trans($translationKey.'.title'),
            $this->translator->trans($translationKey.'.detail'),
            $type,
            $headers,
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
