<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PortfolioOperationOpenApiContractTest extends TestCase
{
    public function testTheOperationRoutesAreDocumentedWithTheirGuards(): void
    {
        $document = self::map(Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml'));
        $paths = self::map($document['paths'] ?? null);

        foreach ([
            ['/api/v1/accounts/{accountId}/portfolio-operations', 'get', 'listPortfolioOperations'],
            ['/api/v1/accounts/{accountId}/portfolio-operations', 'post', 'recordPortfolioOperation'],
            ['/api/v1/accounts/{accountId}/portfolio-operations/preview', 'post', 'previewPortfolioOperation'],
            ['/api/v1/portfolio-operations/{id}', 'get', 'readPortfolioOperation'],
            ['/api/v1/portfolio-operations/{id}/void', 'post', 'voidPortfolioOperation'],
            ['/api/v1/portfolio-operations/{id}/correct', 'post', 'correctPortfolioOperation'],
            ['/api/v1/accounts/{accountId}/portfolio-transfer-candidates', 'get', 'listPortfolioTransferCandidates'],
        ] as [$path, $method, $operationId]) {
            $operation = self::map(self::map($paths[$path] ?? null)[$method] ?? null);
            self::assertSame($operationId, $operation['operationId'] ?? null);
        }

        foreach ([['/api/v1/accounts/{accountId}/portfolio-operations', 'post'], ['/api/v1/portfolio-operations/{id}/correct', 'post']] as [$path, $method]) {
            $parameters = json_encode(self::map(self::map($paths[$path] ?? null)[$method] ?? null)['parameters'] ?? null, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            self::assertStringContainsString('#/components/parameters/IdempotencyKey', $parameters);
            self::assertStringContainsString('#/components/parameters/CsrfToken', $parameters);
        }
    }

    public function testTheOperationSchemaNeverAcceptsACashEffectAndPublishesEveryProblemCode(): void
    {
        $document = self::map(Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml'));
        $schemas = self::map(self::map($document['components'] ?? null)['schemas'] ?? null);

        $request = self::map($schemas['PortfolioOperationRequest'] ?? null);
        $properties = self::map($request['properties'] ?? null);
        self::assertArrayNotHasKey('cashAmount', $properties);
        self::assertFalse($request['additionalProperties'] ?? true);
        self::assertSame(['type', 'tradeDate'], $request['required'] ?? null);

        $operation = self::map($schemas['PortfolioOperation'] ?? null);
        self::assertSame(
            ['id', 'accountId', 'type', 'tradeDate', 'settlementDate', 'sequence', 'activeDayOperationCount', 'instrument', 'counterInstrument', 'quantity', 'unitPrice', 'grossAmount', 'fees', 'taxes', 'cashAmount', 'costBasis', 'counterQuantity', 'ratio', 'costAllocationRatio', 'roundingResidual', 'reference', 'note', 'linkedTransactionId', 'state', 'voidedAt', 'voidReason', 'voidKind', 'correctsOperationId', 'correctedByOperationId', 'version', 'legs', 'withheldIncomeTax', 'withheldSocialContributions', 'netPaidAmount'],
            $operation['required'] ?? null,
        );
        self::assertSame(10_000, self::map(self::map($operation['properties'] ?? null)['activeDayOperationCount'] ?? null)['maximum'] ?? null);

        $problem = self::map(self::map($schemas['PortfolioOperationProblem'] ?? null)['properties'] ?? null);
        $codes = self::map($problem['code'] ?? null)['enum'] ?? null;
        self::assertIsArray($codes);
        foreach ([
            'account_not_portfolio', 'portfolio_account_inactive', 'invalid_operation_type', 'unsupported_operation_type', 'operation_field_required',
            'operation_field_not_allowed', 'invalid_operation_amount', 'invalid_operation_quantity', 'quantity_precision_exceeded',
            'price_precision_exceeded', 'operation_asset_mismatch', 'instrument_inactive', 'instrument_currency_mismatch', 'unknown_instrument',
            'invalid_corporate_action', 'invalid_operation_date', 'invalid_settlement_date', 'operation_amount_mismatch',
            'linked_transfer_mismatch', 'invalid_operation_text', 'linked_transfer_taken', 'operation_voided', 'stale_operation_version',
            'operation_limit_reached', 'surrender_exceeds_available', 'premium_allocation_exceeds_net', 'leg_amount_mismatch',
            'unknown_leg_instrument', 'duplicate_leg_instrument', 'invalid_leg_direction', 'invalid_surrender_amounts',
            'full_surrender_positions_remaining', 'full_surrender_not_balanced', 'operations_after_full_surrender',
        ] as $code) {
            self::assertContains($code, $codes);
        }
        self::assertArrayHasKey('expectedGrossAmount', $problem);
    }

    public function testCorrectionPreviewTargetIsAnOptionalUuidQueryAndKeepsTheOperationBody(): void
    {
        $document = self::map(Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml'));
        $paths = self::map($document['paths']);
        $post = self::map(self::map($paths['/api/v1/accounts/{accountId}/portfolio-operations/preview'])['post']);
        $parameters = $post['parameters'];
        self::assertIsArray($parameters);
        $targets = array_values(array_filter($parameters, static fn (mixed $parameter): bool => is_array($parameter) && 'correctingOperationId' === ($parameter['name'] ?? null)));
        self::assertCount(1, $targets);
        $target = self::map($targets[0]);
        self::assertSame('query', $target['in']);
        self::assertFalse($target['required']);
        self::assertSame(['type' => 'string', 'format' => 'uuid'], $target['schema']);
        $body = self::map(self::map(self::map($post['requestBody'])['content'])['application/json']);
        self::assertSame(['$ref' => '#/components/schemas/PortfolioOperationRequest'], $body['schema']);
        self::assertIsArray($post['responses']);
        self::assertArrayHasKey(409, $post['responses']);
    }

    public function testInsuranceLegsWithholdingsAndPreviewStayExactAndBounded(): void
    {
        $document = self::map(Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml'));
        $schemas = self::map(self::map($document['components'] ?? null)['schemas'] ?? null);
        $request = self::map(self::map($schemas['PortfolioOperationRequest'])['properties']);
        self::assertSame(20, self::map($request['legs'])['maxItems']);
        self::assertArrayHasKey('withheldIncomeTax', $request);
        self::assertArrayHasKey('withheldSocialContributions', $request);
        self::assertArrayNotHasKey('netPaidAmount', $request);
        $leg = self::map($schemas['PortfolioOperationLegInput']);
        self::assertFalse($leg['additionalProperties']);
        self::assertSame(['direction', 'instrumentId', 'amount'], $leg['required']);
        $properties = self::map($leg['properties']);
        self::assertSame(['string', 'null'], self::map($properties['quantity'])['type']);
        self::assertSame(['string', 'null'], self::map($properties['unitPrice'])['type']);
        $preview = self::map($schemas['PortfolioOperationPreview']);
        self::assertSame(['cashAmount', 'roundingResidual', 'expectedGrossAmount', 'legs', 'netPaidAmount'], $preview['required']);
        $types = self::map($schemas['PortfolioOperationType'])['enum'];
        self::assertIsArray($types);
        foreach (['PREMIUM', 'PARTIAL_SURRENDER', 'FULL_SURRENDER'] as $type) {
            self::assertContains($type, $types);
        }
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);

        $typed = [];
        foreach ($value as $key => $item) {
            self::assertIsString($key);
            $typed[$key] = $item;
        }

        return $typed;
    }
}
