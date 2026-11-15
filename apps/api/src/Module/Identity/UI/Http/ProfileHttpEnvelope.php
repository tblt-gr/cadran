<?php

declare(strict_types=1);

namespace App\Module\Identity\UI\Http;

use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The request and response envelope shared by the owner profile endpoints.
 *
 * Each rejection carries its own problem `type` rather than a generic 422, so
 * the settings screen can attach the message to the field that caused it
 * without parsing prose. The bodies are credentials, hence the tight size
 * ceiling and the `no-store` on every answer.
 */
final readonly class ProfileHttpEnvelope
{
    public const string TYPE_INVALID_CURRENT_PASSWORD = '/problems/invalid-current-password';
    public const string TYPE_WEAK_PASSWORD = '/problems/weak-password';
    public const string TYPE_COMMON_PASSWORD = '/problems/common-password';
    public const string TYPE_REUSED_PASSWORD = '/problems/reused-password';
    public const string TYPE_INVALID_DISPLAY_NAME = '/problems/invalid-display-name';

    public function __construct(private JsonRequestEnvelope $envelope)
    {
    }

    /** @return array<mixed>|Response */
    public function readBody(Request $request): array|Response
    {
        return $this->envelope->body($request, BodyLimit::Small, 'api.problem.profile_payload_too_large');
    }

    /**
     * Reads exactly the expected string fields and refuses any other key, so a
     * client that believes it is editing the email through this body is told
     * rather than silently ignored.
     *
     * @param array<mixed> $body
     * @param list<string> $fields
     *
     * @return array<string, string>|null null when a key is missing, extra or not a string
     */
    public static function strings(array $body, array $fields): ?array
    {
        $keys = array_keys($body);
        sort($keys);
        $expected = $fields;
        sort($expected);
        if ($keys !== $expected) {
            return null;
        }

        $values = [];
        foreach ($fields as $field) {
            if (!is_string($body[$field])) {
                return null;
            }

            $values[$field] = $body[$field];
        }

        return $values;
    }

    /** @param array<string, mixed> $data */
    public function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return $this->envelope->json($data, $status);
    }

    /** @param array<string, string> $headers */
    public function problem(
        int $status,
        string $translationKey,
        string $type = ApiProblem::TYPE_BLANK,
        array $headers = [],
    ): JsonResponse {
        return $this->envelope->problem($status, $translationKey, $type, headers: $headers);
    }
}
