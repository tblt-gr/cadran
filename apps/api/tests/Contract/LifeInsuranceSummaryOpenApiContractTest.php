<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class LifeInsuranceSummaryOpenApiContractTest extends TestCase
{
    public function testReadRouteExactAmountsAndAbsenceOfTaxEstimation(): void
    {
        $document = self::map(Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml'));
        $paths = self::map($document['paths']);
        $get = self::map(self::map($paths['/api/v1/accounts/{accountId}/life-insurance-summary'] ?? null)['get'] ?? null);
        self::assertSame('readLifeInsuranceSummary', $get['operationId']);
        self::assertSame([['sessionCookie' => []]], $get['security']);
        self::assertStringNotContainsString('CsrfToken', json_encode($get['parameters'], JSON_THROW_ON_ERROR));
        $schemas = self::map(self::map($document['components'])['schemas']);
        $properties = self::map(self::map($schemas['LifeInsuranceSummary'])['properties']);
        self::assertSame(60, self::map($properties['taxYears'])['maxItems']);
        self::assertSame('#/components/schemas/PortfolioGainFigure', self::map($properties['gainSinceOpening'])['$ref']);
        $tax = self::map(self::map($schemas['LifeInsuranceTaxYear'])['properties']);
        self::assertSame(['year', 'premiums', 'surrendersGross', 'surrendersNetPaid', 'withheldIncomeTax', 'withheldSocialContributions'], array_keys($tax));
        foreach (array_slice($tax, 1) as $amount) {
            self::assertSame('string', self::map($amount)['type']);
        }
        $estimate = self::map(self::map($properties['taxEstimate'])['properties']);
        self::assertSame('NO_RULE_SET', self::map($estimate['status'])['const']);
        self::assertSame('null', self::map($estimate['ruleSetId'])['type']);
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
