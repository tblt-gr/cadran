<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Foundation\UI\Http\ApiProblem;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Envelope, status and JSON helpers shared by the product-model controllers.
 */
final readonly class ProductModelHttp
{
    public function __construct(private JsonRequestEnvelope $envelope, private TranslatorInterface $translator)
    {
    }

    /**
     * Envelope checks only: the media type, the size ceiling and the JSON
     * syntax each answer their own status, so they stay beside the mapping
     * rather than travelling as exceptions.
     *
     * @return array<mixed>|Response
     */
    public function readBody(Request $request): array|Response
    {
        return $this->envelope->body($request, BodyLimit::Large, 'api.problem.product_model_payload_too_large');
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
}
