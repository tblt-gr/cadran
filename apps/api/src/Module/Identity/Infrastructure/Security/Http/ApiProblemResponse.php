<?php

declare(strict_types=1);

namespace App\Module\Identity\Infrastructure\Security\Http;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * RFC 9457 problem document for the firewall's HTTP outcomes. Mirrors the shape
 * that Foundation's ApiProblemResponseListener emits for routing errors.
 */
final class ApiProblemResponse
{
    public static function build(int $status, string $title, string $detail): JsonResponse
    {
        return new JsonResponse(
            data: [
                'type' => 'about:blank',
                'title' => $title,
                'status' => $status,
                'detail' => $detail,
            ],
            status: $status,
            headers: [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'application/problem+json',
            ],
        );
    }
}
