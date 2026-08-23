<?php

declare(strict_types=1);

namespace App\Module\Foundation\UI\Http;

use App\Module\Foundation\Application\GetFoundationStatus;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final class FoundationStatusController
{
    #[Route('/api/v1/status', name: 'api_v1_foundation_status', methods: ['GET'])]
    public function __invoke(GetFoundationStatus $getFoundationStatus): JsonResponse
    {
        $status = $getFoundationStatus();

        return new JsonResponse(
            data: [
                'status' => $status->status,
                'apiVersion' => $status->apiVersion,
            ],
            headers: ['Cache-Control' => 'no-store'],
        );
    }
}
