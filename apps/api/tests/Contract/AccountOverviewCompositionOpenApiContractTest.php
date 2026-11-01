<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class AccountOverviewCompositionOpenApiContractTest extends TestCase
{
    public function testTheCompositionOperationIsSessionProtectedAndBounded(): void
    {
        $document = self::map(Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml'));
        $get = self::map(self::map(self::map($document['paths'])['/api/v1/accounts/overview/composition'])['get']);

        self::assertSame('readAccountOverviewComposition', $get['operationId']);
        self::assertSame([['sessionCookie' => []]], $get['security']);
        $responses = self::map($get['responses']);
        foreach (['200', '400', '401', '403', '422', '500'] as $status) {
            self::assertArrayHasKey($status, $responses);
        }

        $schemas = self::map(self::map($document['components'])['schemas']);
        $composition = self::map($schemas['AccountOverviewComposition']);
        self::assertFalse($composition['additionalProperties']);
        self::assertSame(['asOf', 'total', 'totalReason', 'chartReason', 'accounts'], $composition['required']);
        self::assertSame(1000, self::map(self::map($composition['properties'])['accounts'])['maxItems']);
        self::assertSame(
            ['MISSING_VALUATION', 'MIXED_ASSETS', 'ZERO_TOTAL', 'NEGATIVE_TOTAL', 'NEGATIVE_COMPONENT'],
            self::map($schemas['AccountOverviewCompositionChartReason'])['enum'],
        );
        $account = self::map($schemas['AccountOverviewCompositionAccount']);
        self::assertFalse($account['additionalProperties']);
        self::assertSame(['id', 'label', 'institution', 'primaryGroupId', 'color', 'value', 'share'], $account['required']);
    }

    public function testEveryPortfolioListItemPublishesItsReturnTrend(): void
    {
        $document = self::map(Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml'));
        $schemas = self::map(self::map($document['components'])['schemas']);

        $card = self::map($schemas['PortfolioOverviewAccountCard']);
        self::assertIsArray($card['required']);
        self::assertContains('returnTrend', $card['required']);
        self::assertSame(['$ref' => '#/components/schemas/PortfolioReturnTrend'], self::map($card['properties'])['returnTrend']);

        $trend = self::map($schemas['PortfolioReturnTrend']);
        self::assertFalse($trend['additionalProperties']);
        self::assertSame(['months', 'from', 'to', 'cumulativeReturn', 'reason', 'reasonMonth'], $trend['required']);
        $months = self::map(self::map($trend['properties'])['months']);
        self::assertSame(12, $months['minItems']);
        self::assertSame(12, $months['maxItems']);
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);
        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }
}
