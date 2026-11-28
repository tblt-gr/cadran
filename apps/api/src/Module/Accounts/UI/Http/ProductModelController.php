<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\ListProductModels;
use App\Module\Accounts\Application\ReadProductModel;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Adds HTTP to the workspace product-model use cases. Field-level validation
 * lives in {@see ProductModelPayload}, the wire shape in
 * {@see ProductModelRepresentation} and the status mapping in
 * {@see ProductModelProblems}; what remains here is the routing. Writes live
 * in {@see ProductModelWriteController}.
 *
 * There is no endpoint that edits a recorded period, and none that deletes
 * one. A model changes by recording a new dated period, which is what keeps a
 * past statement resolvable against the figures that covered it.
 */
final readonly class ProductModelController
{
    public function __construct(private JsonRequestEnvelope $envelope, private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/product-models', name: 'api_v1_product_models_list', methods: ['GET'])]
    public function list(Request $request, ListProductModels $listProductModels): Response
    {
        $page = $request->query->getString('page');
        $pageSize = $request->query->getString('pageSize');
        $includeArchived = $request->query->getString('includeArchived');
        if (!self::unsignedIntegerOrEmpty($page) || !self::unsignedIntegerOrEmpty($pageSize) || !self::flag($includeArchived)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_product_model_query');
        }

        try {
            $models = $listProductModels(
                'true' === $includeArchived,
                '' === $page ? null : (int) $page,
                '' === $pageSize ? null : (int) $pageSize,
            );
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, ProductModelProblems::list());
        }

        return $this->envelope->json(ProductModelRepresentation::page($models));
    }

    #[Route('/api/v1/product-models/{id}', name: 'api_v1_product_models_read', methods: ['GET'])]
    public function read(string $id, ReadProductModel $readProductModel): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.product_model_not_found');
        }

        try {
            $model = $readProductModel($id);
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, ProductModelProblems::read());
        }

        return $this->envelope->json(ProductModelRepresentation::one($model));
    }

    private static function flag(string $value): bool
    {
        return in_array($value, ['', 'true', 'false'], true);
    }

    private static function unsignedIntegerOrEmpty(string $value): bool
    {
        return '' === $value || 1 === preg_match('/^[0-9]{1,4}$/D', $value);
    }
}
