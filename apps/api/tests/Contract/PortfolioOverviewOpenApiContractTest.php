<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PortfolioOverviewOpenApiContractTest extends TestCase
{
    public function testTheThreeReadContractsPublishExactAmountsAndBoundedSources(): void
    {
        $document = self::map(Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml'));
        $paths = self::map($document['paths']);
        foreach ([
            '/api/v1/portfolios' => 'listPortfolios',
            '/api/v1/accounts/{accountId}/portfolio-summary' => 'readPortfolioSummary',
            '/api/v1/accounts/{accountId}/portfolio-allocation' => 'readPortfolioAllocation',
        ] as $path => $id) {
            $get = self::map(self::map($paths[$path])['get']);
            self::assertSame($id, $get['operationId']);
            self::assertSame([['sessionCookie' => []]], $get['security']);
            $responses = $get['responses'];
            self::assertIsArray($responses);
            foreach (['200', '401', '403', '404', '422'] as $status) {
                self::assertArrayHasKey($status, $responses);
            }
        }
        $schemas = self::map(self::map($document['components'])['schemas']);
        foreach (['PortfolioOverviewAccountCard', 'PortfolioOverviewSummary', 'PortfolioOverviewAllocation'] as $schema) {
            $properties = self::map(self::map($schemas[$schema])['properties']);
            self::assertArrayHasKey('newerPartialValuation', $properties);
            self::assertSame([['$ref' => '#/components/schemas/PortfolioOverviewValuation'], ['type' => 'null']], self::map($properties['newerPartialValuation'])['oneOf']);
        }
        $summary = self::map(self::map($schemas['PortfolioOverviewSummary'])['properties']);
        self::assertSame(['string', 'null'], self::map($summary['totalValue'])['type']);
        self::assertSame('string', self::map($summary['cash'])['type']);
        $explanation = self::map(self::map($schemas['PortfolioKpiExplanation'])['properties']);
        self::assertSame(50, self::map($explanation['sources'])['maxItems']);
        $list = self::map(self::map($schemas['PortfolioOverviewList'])['properties']);
        self::assertSame(50, self::map($list['items'])['maxItems']);
        self::assertSame(['assetCode', 'totalValue', 'unrealizedGain', 'costBasis', 'performance', 'valuedAccounts', 'unvaluedAccounts', 'oldestValuedOn', 'partialNewerAccounts', 'reason'], self::map($list['total'])['required']);
        $card = self::map(self::map($schemas['PortfolioOverviewAccountCard'])['properties']);
        self::assertSame(['value', 'costBasis', 'performance', 'reason'], self::map($card['unrealizedGain'])['required']);
        $allocation = self::map(self::map($schemas['PortfolioOverviewAllocation'])['properties']);
        self::assertSame(8, self::map($allocation['slices'])['maxItems']);
    }

    public function testTheHoldingsContractIsBoundedExactAndClosed(): void
    {
        $document = self::map(Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml'));
        $get = self::map(self::map(self::map($document['paths'])['/api/v1/holdings'])['get']);
        self::assertSame('readHoldings', $get['operationId']);
        self::assertSame([['sessionCookie' => []]], $get['security']);
        $responses = $get['responses'];
        self::assertIsArray($responses);
        foreach (['200', '400', '401', '403', '422', '500'] as $status) {
            self::assertArrayHasKey($status, $responses);
        }
        $schemas = self::map(self::map($document['components'])['schemas']);
        $holdings = self::map($schemas['Holdings']);
        self::assertFalse($holdings['additionalProperties']);
        self::assertSame(['asOf', 'assetCode', 'valuedFrom', 'valuedTo', 'quality', 'warnings', 'totals', 'items', 'allocation', 'unsplitAccounts', 'accounts', 'truncated'], $holdings['required']);
        $properties = self::map($holdings['properties']);
        self::assertSame(1000, self::map($properties['items'])['maxItems']);
        self::assertSame(50, self::map($properties['unsplitAccounts'])['maxItems']);
        self::assertSame(50, self::map($properties['accounts'])['maxItems']);
        self::assertSame(500, self::map(self::map(self::map($schemas['HoldingsAccountSummary'])['properties'])['lines'])['maxItems']);
        foreach (['Holding', 'HoldingAccountLine', 'HoldingsTotals', 'HoldingAllocationSlice', 'HoldingsShare', 'HoldingsAccountSummary', 'HoldingsAccountSummaryLine'] as $schema) {
            self::assertFalse(self::map($schemas[$schema])['additionalProperties'], $schema);
        }
        $amount = self::map($schemas['HoldingsNullableDecimal']);
        self::assertSame(['string', 'null'], $amount['type']);
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);
        $result = [];
        foreach ($value as $key => $item) {
            self::assertIsString($key);
            $result[$key] = $item;
        }

        return $result;
    }
}
