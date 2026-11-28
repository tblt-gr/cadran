<?php

declare(strict_types=1);

namespace App\Module\Catalog\UI\Http;

use App\Module\Catalog\Application\InvalidProductQuery;
use App\Module\Catalog\Application\ListProducts;
use App\Module\Catalog\Application\ProductNotFound;
use App\Module\Catalog\Application\ProductPage;
use App\Module\Catalog\Application\ReadProduct;
use App\Module\Catalog\Domain\EffectiveProduct;
use App\Module\Catalog\Domain\EffectiveRule;
use App\Module\Catalog\Domain\ProductRule;
use App\Module\Catalog\Domain\RuleKind;
use App\Module\Foundation\UI\Http\JsonRequestEnvelope;
use App\Module\Foundation\UI\Http\QueryParameters;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Read-only view of the system product catalogue, resolved on a business date.
 *
 * The catalogue is global and identical for every session, so no workspace is
 * resolved here; the firewall still denies an anonymous request under /api/.
 */
final class ProductCatalogController
{
    public function __construct(private readonly JsonRequestEnvelope $envelope)
    {
    }

    #[Route('/api/v1/products', name: 'api_v1_products_list', methods: ['GET'])]
    public function list(Request $request, ListProducts $listProducts): Response
    {
        $page = $request->query->getString('page');
        $pageSize = $request->query->getString('pageSize');
        $asOf = $request->query->getString('asOf');

        if (!QueryParameters::isUnsignedIntegerOrEmpty($page) || !QueryParameters::isUnsignedIntegerOrEmpty($pageSize)) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_product_query');
        }

        try {
            $catalogue = $listProducts(
                QueryParameters::integerOrNull($page),
                QueryParameters::integerOrNull($pageSize),
                '' === $asOf ? null : $asOf,
            );
        } catch (InvalidProductQuery) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_product_query');
        }

        return $this->jsonPage($catalogue);
    }

    #[Route('/api/v1/products/{code}', name: 'api_v1_products_read', methods: ['GET'])]
    public function read(string $code, Request $request, ReadProduct $readProduct): Response
    {
        $asOf = $request->query->getString('asOf');

        try {
            $product = $readProduct($code, '' === $asOf ? null : $asOf);
        } catch (InvalidProductQuery) {
            return $this->envelope->problem(Response::HTTP_BAD_REQUEST, 'api.problem.invalid_product_query');
        } catch (ProductNotFound) {
            return $this->envelope->problem(Response::HTTP_NOT_FOUND, 'api.problem.product_not_found');
        }

        return $this->envelope->json(self::represent($product));
    }

    private function jsonPage(ProductPage $catalogue): JsonResponse
    {
        return $this->envelope->json([
            'items' => array_map(self::represent(...), $catalogue->products),
            'page' => $catalogue->page,
            'pageSize' => $catalogue->pageSize,
            'total' => $catalogue->total,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private static function represent(EffectiveProduct $effective): array
    {
        $product = $effective->product;

        return [
            'code' => $product->code->toString(),
            'displayName' => $product->displayName,
            'jurisdiction' => $product->jurisdiction,
            'accountKind' => $product->accountKind->value,
            'wrapperKind' => $product->wrapperKind->value,
            'yieldKind' => $product->yieldKind->value,
            // Stated by the server so no interface has to infer a promise from
            // a product name: a PEA, a CTO or a contract in units of account
            // earns what its assets earn, and never a catalogue rate.
            'yieldGuaranteed' => $product->yieldKind->isGuaranteed(),
            // Also stated by the server: a PEA is capped on the contributions
            // paid in, whatever the plan is worth, and no screen may infer that
            // from which ceiling rule happens to be sourced on the day it reads.
            'ceilingBasis' => $product->wrapperKind->ceilingBasis()->value,
            'defaultGroupCode' => $product->defaultGroupCode,
            // The capability list is the public activation contract. Clients
            // never infer behavior from a localized product name.
            'capabilities' => $product->capabilities->toStrings(),
            'catalogVersion' => $product->catalogVersion,
            'archivedAt' => $product->archivedAt?->format(\DateTimeInterface::ATOM),
            'asOf' => $effective->asOf->format('Y-m-d'),
            'rules' => array_map(self::representRule(...), $effective->rules),
            // The rules this product is expected to carry and that no sourced
            // period covers on this date. A screen shows them as unavailable;
            // it never shows them as zero.
            'unavailableRuleKinds' => array_map(
                static fn (RuleKind $kind): string => $kind->value,
                $effective->unavailableRuleKinds,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function representRule(EffectiveRule $effective): array
    {
        $rule = $effective->rule;
        $amount = $rule->value->amount;

        return [
            'kind' => $rule->kind->value,
            'valueType' => $rule->value->type->value,
            // A decimal always leaves as a canonical string paired with its
            // asset. A JSON number would hand the client a binary float.
            'amount' => null === $amount ? null : [
                'value' => $amount->value->toString(),
                'assetCode' => $amount->asset->toString(),
            ],
            // Expressed in percent as the source published it: `1.7` reads
            // 1.7 %, so no client multiplies a rate to display it.
            'percentage' => $rule->value->percentage?->toString(),
            'text' => $rule->value->text,
            'validFrom' => $rule->period->validFrom->format('Y-m-d'),
            'validTo' => $rule->period->validTo?->format('Y-m-d'),
            'verification' => $effective->verification->value,
            'verifiedOn' => $rule->verifiedOn?->format('Y-m-d'),
            'verifiedBy' => $rule->verifiedBy,
            'source' => self::representSource($rule),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function representSource(ProductRule $rule): array
    {
        return [
            'publisher' => $rule->source->publisher,
            'title' => $rule->source->title,
            'url' => $rule->source->url,
            'publishedOn' => $rule->source->publishedOn?->format('Y-m-d'),
            'retrievedOn' => $rule->source->retrievedOn->format('Y-m-d'),
        ];
    }
}
