<?php

declare(strict_types=1);

namespace App\Module\Accounts\UI\Http;

use App\Module\Accounts\Application\AddProductModelRule;
use App\Module\Accounts\Application\ArchiveProductModel;
use App\Module\Accounts\Application\CreateProductModel;
use App\Module\Accounts\Application\CreateProductModelFromProduct;
use App\Module\Accounts\Application\CreateProductModelInput;
use App\Module\Accounts\Application\DeclaredRuleInput;
use App\Module\Accounts\Application\DuplicateProductModel;
use App\Module\Accounts\Application\InvalidProductModelInput;
use App\Module\Accounts\Application\ListProductModels;
use App\Module\Accounts\Application\ProductModelArchived;
use App\Module\Accounts\Application\ProductModelConflict;
use App\Module\Accounts\Application\ProductModelInputParser;
use App\Module\Accounts\Application\ProductModelNotFound;
use App\Module\Accounts\Application\RateBracketInput;
use App\Module\Accounts\Application\ReadProductModel;
use App\Module\Accounts\Application\StaleProductModelVersion;
use App\Module\Catalog\Domain\ProductCapability;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\UI\Http\ApiProblem;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Adds HTTP to the workspace product-model use cases. Field-level validation
 * lives in {@see ProductModelPayload} and the wire shape in
 * {@see ProductModelRepresentation}; what remains here is the envelope, the
 * routing and the status mapping.
 *
 * There is no endpoint that edits a recorded period, and none that deletes
 * one. A model changes by recording a new dated period, which is what keeps a
 * past statement resolvable against the figures that covered it.
 */
final readonly class ProductModelController
{
    /**
     * A name already taken and a stale version are both 409, but they ask the
     * caller for opposite things: pick another name, or reload and reapply.
     * The problem type is what lets a client tell them apart.
     */
    public const string TYPE_NAME_TAKEN = '/problems/product-model-name-taken';
    public const string TYPE_STALE_VERSION = '/problems/stale-version';
    public const string TYPE_ARCHIVED = '/problems/product-model-archived';

    private const int MAX_BODY_BYTES = 32_768;
    private const array RULE_FIELDS = [
        'kind', 'amount', 'amountAssetCode', 'text', 'rateApplication', 'brackets', 'validFrom', 'validTo',
    ];
    private const array BRACKET_FIELDS = ['lowerBound', 'upperBound', 'percentage'];

    public function __construct(private TranslatorInterface $translator)
    {
    }

    #[Route('/api/v1/product-models', name: 'api_v1_product_models_list', methods: ['GET'])]
    public function list(Request $request, ListProductModels $listProductModels): Response
    {
        $page = $request->query->getString('page');
        $perPage = $request->query->getString('perPage');
        $includeArchived = $request->query->getString('includeArchived');
        if (!self::unsignedIntegerOrEmpty($page) || !self::unsignedIntegerOrEmpty($perPage) || !self::flag($includeArchived)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_product_model_query');
        }

        try {
            $models = $listProductModels(
                'true' === $includeArchived,
                '' === $page ? null : (int) $page,
                '' === $perPage ? null : (int) $perPage,
            );
        } catch (InvalidProductModelInput) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_product_model_query');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.product_model_forbidden');
        }

        return self::json(ProductModelRepresentation::page($models));
    }

    #[Route('/api/v1/product-models', name: 'api_v1_product_models_create', methods: ['POST'])]
    public function create(Request $request, CreateProductModel $createProductModel): Response
    {
        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = ProductModelPayload::of($body, [
                'name', 'family', 'wrapperKind', 'yieldKind', 'defaultGroupCode',
                'valuationMode', 'capabilities', 'rules',
            ]);
            $model = $createProductModel(new CreateProductModelInput(
                name: $payload->string('name'),
                family: $payload->string('family'),
                wrapperKind: $payload->string('wrapperKind'),
                yieldKind: $payload->string('yieldKind'),
                defaultGroupCode: $payload->nullableString('defaultGroupCode'),
                valuationMode: $payload->string('valuationMode'),
                capabilities: $payload->strings('capabilities', count(ProductCapability::cases())),
                rules: self::rules($payload),
            ));
        } catch (InvalidProductModelInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_product_model');
        } catch (ProductModelConflict) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.product_model_name_taken', self::TYPE_NAME_TAKEN);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.product_model_forbidden');
        }

        return self::json(ProductModelRepresentation::one($model), Response::HTTP_CREATED);
    }

    /**
     * Starting a model from a catalogue product. The copy is made by the
     * server from the catalogue row, so a model claiming to come from a Livret
     * A carries what the catalogue says a Livret A is.
     */
    #[Route('/api/v1/product-models/from-product', name: 'api_v1_product_models_from_product', methods: ['POST'])]
    public function fromProduct(Request $request, CreateProductModelFromProduct $createFromProduct): Response
    {
        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = ProductModelPayload::of($body, ['name', 'productCode', 'valuationMode']);
            $model = $createFromProduct(
                $payload->string('productCode'),
                $payload->string('name'),
                $payload->string('valuationMode'),
            );
        } catch (InvalidProductModelInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_product_model');
        } catch (ProductModelConflict) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.product_model_name_taken', self::TYPE_NAME_TAKEN);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.product_model_forbidden');
        }

        return self::json(ProductModelRepresentation::one($model), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/product-models/{id}', name: 'api_v1_product_models_read', methods: ['GET'])]
    public function read(string $id, ReadProductModel $readProductModel): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.product_model_not_found');
        }

        try {
            $model = $readProductModel($id);
        } catch (ProductModelNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.product_model_not_found');
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.product_model_forbidden');
        }

        return self::json(ProductModelRepresentation::one($model));
    }

    #[Route('/api/v1/product-models/{id}/duplicate', name: 'api_v1_product_models_duplicate', methods: ['POST'])]
    public function duplicate(string $id, Request $request, DuplicateProductModel $duplicateProductModel): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.product_model_not_found');
        }

        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $model = $duplicateProductModel($id, ProductModelPayload::of($body, ['name'])->string('name'));
        } catch (ProductModelArchived) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.product_model_archived', self::TYPE_ARCHIVED);
        } catch (InvalidProductModelInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_product_model');
        } catch (ProductModelNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.product_model_not_found');
        } catch (ProductModelConflict) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.product_model_name_taken', self::TYPE_NAME_TAKEN);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.product_model_forbidden');
        }

        return self::json(ProductModelRepresentation::one($model), Response::HTTP_CREATED);
    }

    /**
     * Recording a dated period. It is a resource of its own rather than a
     * field of the model: a period is added, and the periods already recorded
     * are not part of any body a client may send back.
     */
    #[Route('/api/v1/product-models/{id}/rules', name: 'api_v1_product_models_add_rule', methods: ['POST'])]
    public function addRule(string $id, Request $request, AddProductModelRule $addProductModelRule): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.product_model_not_found');
        }

        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = ProductModelPayload::of($body, [...self::RULE_FIELDS, 'version']);
            $model = $addProductModelRule($id, self::rule($payload), $payload->integer('version'));
        } catch (ProductModelArchived) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.product_model_archived', self::TYPE_ARCHIVED);
        } catch (InvalidProductModelInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_product_model');
        } catch (ProductModelNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.product_model_not_found');
        } catch (StaleProductModelVersion) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.product_model_stale_version', self::TYPE_STALE_VERSION);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.product_model_forbidden');
        }

        return self::json(ProductModelRepresentation::one($model));
    }

    #[Route('/api/v1/product-models/{id}/archive', name: 'api_v1_product_models_archive', methods: ['POST'])]
    public function archive(string $id, Request $request, ArchiveProductModel $archiveProductModel): Response
    {
        if (!self::identifier($id)) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.product_model_not_found');
        }

        $body = $this->readBody($request);
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $model = $archiveProductModel($id, ProductModelPayload::of($body, ['version'])->integer('version'));
        } catch (ProductModelArchived) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.product_model_archived', self::TYPE_ARCHIVED);
        } catch (InvalidProductModelInput|\UnexpectedValueException) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'api.problem.invalid_product_model');
        } catch (ProductModelNotFound) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'api.problem.product_model_not_found');
        } catch (StaleProductModelVersion) {
            return $this->problem(Response::HTTP_CONFLICT, 'api.problem.product_model_stale_version', self::TYPE_STALE_VERSION);
        } catch (WorkspaceAccessDenied) {
            return $this->problem(Response::HTTP_FORBIDDEN, 'api.problem.product_model_forbidden');
        }

        return self::json(ProductModelRepresentation::one($model));
    }

    /**
     * @return list<DeclaredRuleInput>
     */
    private static function rules(ProductModelPayload $payload): array
    {
        return array_map(
            self::rule(...),
            $payload->objects('rules', self::RULE_FIELDS, ProductModelInputParser::MAX_RULES_PER_REQUEST),
        );
    }

    private static function rule(ProductModelPayload $payload): DeclaredRuleInput
    {
        return new DeclaredRuleInput(
            kind: $payload->string('kind'),
            amount: $payload->nullableString('amount'),
            amountAssetCode: $payload->nullableString('amountAssetCode'),
            text: $payload->nullableString('text'),
            rateApplication: $payload->nullableString('rateApplication'),
            brackets: array_map(
                static fn (ProductModelPayload $bracket): RateBracketInput => new RateBracketInput(
                    lowerBound: $bracket->string('lowerBound'),
                    upperBound: $bracket->nullableString('upperBound'),
                    percentage: $bracket->string('percentage'),
                ),
                $payload->objects('brackets', self::BRACKET_FIELDS, ProductModelInputParser::MAX_BRACKETS),
            ),
            validFrom: $payload->string('validFrom'),
            validTo: $payload->nullableString('validTo'),
        );
    }

    /**
     * Envelope checks only: the media type, the size ceiling and the JSON
     * syntax each answer their own status, so they stay beside the mapping
     * rather than travelling as exceptions.
     *
     * @return array<mixed>|Response
     */
    private function readBody(Request $request): array|Response
    {
        if ('json' !== $request->getContentTypeFormat()) {
            return $this->problem(Response::HTTP_UNSUPPORTED_MEDIA_TYPE, 'api.problem.unsupported_media_type');
        }
        if (mb_strlen($request->getContent(), '8bit') > self::MAX_BODY_BYTES) {
            return $this->problem(Response::HTTP_REQUEST_ENTITY_TOO_LARGE, 'api.problem.product_model_payload_too_large');
        }

        try {
            return $request->toArray();
        } catch (\Throwable) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_request');
        }
    }

    private static function flag(string $value): bool
    {
        return in_array($value, ['', 'true', 'false'], true);
    }

    private static function unsignedIntegerOrEmpty(string $value): bool
    {
        return '' === $value || 1 === preg_match('/^[0-9]{1,4}$/D', $value);
    }

    private static function identifier(string $value): bool
    {
        return 1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $value);
    }

    /** @param array<string, mixed> $data */
    private static function json(array $data, int $status = Response::HTTP_OK): JsonResponse
    {
        return new JsonResponse($data, $status, ['Cache-Control' => 'no-store']);
    }

    private function problem(int $status, string $translationKey, string $type = ApiProblem::TYPE_BLANK): JsonResponse
    {
        return ApiProblem::response(
            $status,
            $this->translator->trans($translationKey.'.title'),
            $this->translator->trans($translationKey.'.detail'),
            $type,
        );
    }
}
