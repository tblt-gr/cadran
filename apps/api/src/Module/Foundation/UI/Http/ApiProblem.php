<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Builds an RFC 9457 `application/problem+json` document. Shared by the
 * Foundation HTTP listeners; the Identity firewall handlers keep their own copy
 * because Infrastructure may not depend on this UI layer.
 */
final class ApiProblem
{
    public const string TYPE_BLANK = 'about:blank';
    public const string TYPE_CSRF = '/problems/csrf-token';

    /**
     * @param array<string, string> $headers extra response headers
     */
    public static function response(int $status, string $title, string $detail, string $type = self::TYPE_BLANK, array $headers = []): JsonResponse
    {
        return new JsonResponse(
            data: [
                'type' => $type,
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
            ],
            status: $status,
            headers: [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'application/problem+json',
                ...$headers,
            ],
        );
    }
}
