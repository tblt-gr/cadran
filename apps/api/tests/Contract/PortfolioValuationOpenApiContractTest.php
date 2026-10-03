<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PortfolioValuationOpenApiContractTest extends TestCase
{
    public function testTheValuationRoutesAreDocumentedWithTheirGuards(): void
    {
        $document = self::map(Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml'));
        $paths = self::map($document['paths'] ?? null);

        foreach ([
            ['/api/v1/accounts/{accountId}/portfolio-valuations', 'get', 'listPortfolioValuations'],
            ['/api/v1/accounts/{accountId}/portfolio-valuations', 'post', 'recordPortfolioValuation'],
            ['/api/v1/accounts/{accountId}/portfolio-valuations/draft', 'get', 'readPortfolioValuationDraft'],
            ['/api/v1/portfolio-valuations/{id}', 'get', 'readPortfolioValuation'],
            ['/api/v1/portfolio-valuations/{id}/withdraw', 'post', 'withdrawPortfolioValuation'],
        ] as [$path, $method, $operationId]) {
            $operation = self::map(self::map($paths[$path] ?? null)[$method] ?? null);
            self::assertSame($operationId, $operation['operationId'] ?? null);
            if ('post' === $method) {
                self::assertStringContainsString('#/components/parameters/CsrfToken', json_encode($operation['parameters'] ?? null, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
            }
        }
        $record = self::map(self::map($paths['/api/v1/accounts/{accountId}/portfolio-valuations'] ?? null)['post'] ?? null);
        self::assertStringContainsString('#/components/parameters/IdempotencyKey', json_encode($record['parameters'] ?? null, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    public function testTheRequestIsClosedAndTheProblemPublishesEveryCode(): void
    {
        $document = self::map(Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml'));
        $schemas = self::map(self::map($document['components'] ?? null)['schemas'] ?? null);

        $request = self::map($schemas['PortfolioValuationRequest'] ?? null);
        self::assertFalse($request['additionalProperties'] ?? true);
        self::assertSame(['valuedOn', 'detail'], $request['required'] ?? null);

        $codes = self::map(self::map(self::map($schemas['PortfolioValuationProblem'] ?? null)['properties'] ?? null)['code'] ?? null)['enum'] ?? null;
        self::assertIsArray($codes);
        foreach ([
            'invalid_valuation_detail', 'valuation_field_not_allowed', 'invalid_valuation_total', 'invalid_valuation_date', 'valuation_price_entry_missing',
            'unknown_valuation_instrument', 'invalid_valuation_price', 'price_precision_exceeded', 'invalid_price_date', 'operation_asset_mismatch',
            'valuation_exists', 'stale_valuation_version', 'valuation_limit_reached',
        ] as $code) {
            self::assertContains($code, $codes);
        }
        $flags = self::map($schemas['PortfolioValuationFlag'] ?? null)['enum'] ?? null;
        self::assertSame(['STALE_PRICES', 'CASH_DIFFERENCE', 'OBSERVED_TOTAL_DIFFERENCE', 'POSITIONS_CHANGED', 'NEGATIVE_SECURITIES_VALUE'], $flags);
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
