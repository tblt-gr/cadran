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
use App\Module\Accounts\Application\ProductModelInputParser;
use App\Module\Accounts\Application\RateBracketInput;
use App\Module\Catalog\Domain\ProductCapability;
use App\Module\Foundation\UI\Http\BodyLimit;
use App\Module\Foundation\UI\Http\Identifier;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\ProblemMapper;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Write side of the product-model resource: creation, duplication, dated
 * periods and archiving. Reads stay in {@see ProductModelController}.
 */
final readonly class ProductModelWriteController
{
    private const array RULE_FIELDS = [
        'kind', 'amount', 'amountAssetCode', 'text', 'rateApplication', 'brackets', 'validFrom', 'validTo',
    ];
    private const array BRACKET_FIELDS = ['lowerBound', 'upperBound', 'percentage'];

    public function __construct(private JsonRequestEnvelope $envelope, private ProblemMapper $problems)
    {
    }

    #[Route('/api/v1/product-models', name: 'api_v1_product_models_create', methods: ['POST'])]
    public function create(Request $request, CreateProductModel $createProductModel): Response
    {
        $body = $this->envelope->body($request, BodyLimit::Large, 'api.problem.product_model_payload_too_large');
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
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, ProductModelProblems::write());
        }

        return $this->envelope->json(ProductModelRepresentation::one($model), Response::HTTP_CREATED);
    }

    /**
     * Starting a model from a catalogue product. The copy is made by the
     * server from the catalogue row, so a model claiming to come from a Livret
     * A carries what the catalogue says a Livret A is.
     */
    #[Route('/api/v1/product-models/from-product', name: 'api_v1_product_models_from_product', methods: ['POST'])]
    public function fromProduct(Request $request, CreateProductModelFromProduct $createFromProduct): Response
    {
        $body = $this->envelope->body($request, BodyLimit::Large, 'api.problem.product_model_payload_too_large');
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
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, ProductModelProblems::write());
        }

        return $this->envelope->json(ProductModelRepresentation::one($model), Response::HTTP_CREATED);
    }

    #[Route('/api/v1/product-models/{id}/duplicate', name: 'api_v1_product_models_duplicate', methods: ['POST'])]
    public function duplicate(string $id, Request $request, DuplicateProductModel $duplicateProductModel): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.product_model_not_found');
        }

        $body = $this->envelope->body($request, BodyLimit::Large, 'api.problem.product_model_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $model = $duplicateProductModel($id, ProductModelPayload::of($body, ['name'])->string('name'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, ProductModelProblems::write());
        }

        return $this->envelope->json(ProductModelRepresentation::one($model), Response::HTTP_CREATED);
    }

    /**
     * Recording a dated period. It is a resource of its own rather than a
     * field of the model: a period is added, and the periods already recorded
     * are not part of any body a client may send back.
     */
    #[Route('/api/v1/product-models/{id}/rules', name: 'api_v1_product_models_add_rule', methods: ['POST'])]
    public function addRule(string $id, Request $request, AddProductModelRule $addProductModelRule): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.product_model_not_found');
        }

        $body = $this->envelope->body($request, BodyLimit::Large, 'api.problem.product_model_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $payload = ProductModelPayload::of($body, [...self::RULE_FIELDS, 'version']);
            $model = $addProductModelRule($id, self::rule($payload), $payload->integer('version'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, ProductModelProblems::write());
        }

        return $this->envelope->json(ProductModelRepresentation::one($model));
    }

    #[Route('/api/v1/product-models/{id}/archive', name: 'api_v1_product_models_archive', methods: ['POST'])]
    public function archive(string $id, Request $request, ArchiveProductModel $archiveProductModel): Response
    {
        if (!Identifier::isValid($id)) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.product_model_not_found');
        }

        $body = $this->envelope->body($request, BodyLimit::Large, 'api.problem.product_model_payload_too_large');
        if ($body instanceof Response) {
            return $body;
        }

        try {
            $model = $archiveProductModel($id, ProductModelPayload::of($body, ['version'])->integer('version'));
        } catch (\Throwable $exception) {
            return $this->problems->respond($exception, ProductModelProblems::write());
        }

        return $this->envelope->json(ProductModelRepresentation::one($model));
    }

    /**
     * @return list<DeclaredRuleInput>
     */
    private static function rules(ProductModelPayload $payload): array
    {
        $rules = [];
        foreach ($payload->objects('rules', self::RULE_FIELDS, ProductModelInputParser::MAX_RULES_PER_REQUEST) as $index => $rule) {
            $rules[] = self::rule($rule, '/rules/'.$index);
        }

        return $rules;
    }

    private static function rule(ProductModelPayload $payload, string $pointer = ''): DeclaredRuleInput
    {
        return new DeclaredRuleInput(
            kind: $payload->string('kind'),
            amount: $payload->raw('amount'),
            amountAssetCode: $payload->raw('amountAssetCode'),
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
            amountPointer: $pointer.'/amount',
            amountAssetCodePointer: $pointer.'/amountAssetCode',
        );
    }
}
