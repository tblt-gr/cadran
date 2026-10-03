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
        $summary = self::map(self::map($schemas['PortfolioOverviewSummary'])['properties']);
        self::assertSame(['string', 'null'], self::map($summary['totalValue'])['type']);
        self::assertSame('string', self::map($summary['cash'])['type']);
        $explanation = self::map(self::map($schemas['PortfolioKpiExplanation'])['properties']);
        self::assertSame(50, self::map($explanation['sources'])['maxItems']);
        $list = self::map(self::map($schemas['PortfolioOverviewList'])['properties']);
        self::assertSame(50, self::map($list['items'])['maxItems']);
        $allocation = self::map(self::map($schemas['PortfolioOverviewAllocation'])['properties']);
        self::assertSame(8, self::map($allocation['slices'])['maxItems']);
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
