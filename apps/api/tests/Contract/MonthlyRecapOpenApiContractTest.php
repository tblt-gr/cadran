<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class MonthlyRecapOpenApiContractTest extends TestCase
{
    public function testAnAxisPayloadDeclaresEveryPropertyAllowedByItsClosedSchema(): void
    {
        $axis = $this->schema('MonthlyRecapAxis');
        $validPayload = [
            'axis' => 'ESSENTIAL',
            'kpi' => null,
            'value' => '1250.00',
            'assetCode' => 'EUR',
            'reason' => null,
            'pendingCount' => 0,
            'sourceTransactionIds' => ['00000000-0000-7000-8000-000000000001'],
            'sourceTransactions' => [[
                'id' => '00000000-0000-7000-8000-000000000001',
                'bookedOn' => '2026-09-01',
                'label' => 'Loyer',
                'amount' => ['value' => '-1250.00', 'assetCode' => 'EUR'],
                'state' => 'BOOKED',
            ]],
            'sourceTransferIds' => [],
        ];

        self::assertSame('object', $axis['type'] ?? null);
        self::assertFalse($axis['additionalProperties'] ?? true);
        self::assertArrayNotHasKey('allOf', $axis);
        self::assertEqualsCanonicalizing(array_keys($validPayload), $axis['required'] ?? []);
        $properties = self::map($axis['properties'] ?? null);
        foreach (array_keys($validPayload) as $property) {
            self::assertArrayHasKey($property, $properties);
        }
        self::assertSame(500, self::map($properties['sourceTransactions'])['maxItems'] ?? null);
        self::assertSame(
            '#/components/schemas/MonthlyKpiSourceTransaction',
            self::map(self::map($properties['sourceTransactions'])['items'] ?? null)['$ref'] ?? null,
        );
    }

    public function testCategorySourcesUseTheSameBoundedTransactionSummaryContract(): void
    {
        $category = $this->schema('MonthlyRecapCategory');
        $properties = self::map($category['properties'] ?? null);
        $sources = self::map($properties['sourceTransactions'] ?? null);
        $required = $category['required'] ?? null;

        self::assertIsArray($required);
        self::assertContains('sourceTransactions', $required);
        self::assertSame(500, $sources['maxItems'] ?? null);
        self::assertSame(
            '#/components/schemas/MonthlyKpiSourceTransaction',
            self::map($sources['items'] ?? null)['$ref'] ?? null,
        );
    }

    public function testSavingRecapPreferencesDeclaresTheRequiredCsrfHeader(): void
    {
        $document = $this->document();
        $paths = self::map($document['paths'] ?? null);
        $preferences = self::map($paths['/api/v1/reports/monthly/recap-preferences'] ?? null);
        $put = self::map($preferences['put'] ?? null);
        $parameters = $put['parameters'] ?? null;

        self::assertIsArray($parameters);
        self::assertContains(['$ref' => '#/components/parameters/CsrfToken'], $parameters);
    }

    /** @return array<string, mixed> */
    private function schema(string $name): array
    {
        $document = $this->document();
        $components = self::map($document['components'] ?? null);
        $schemas = self::map($components['schemas'] ?? null);

        return self::map($schemas[$name] ?? null);
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return self::map(Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml'));
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        if (!is_array($value)) {
            self::fail('Expected an OpenAPI mapping.');
        }
        $map = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                self::fail('Expected an OpenAPI mapping with string keys.');
            }
            $map[$key] = $item;
        }

        return $map;
    }
}
