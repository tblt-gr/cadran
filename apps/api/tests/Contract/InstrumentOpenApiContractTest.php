<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class InstrumentOpenApiContractTest extends TestCase
{
    public function testInstrumentRoutesAndTypedSchemasArePublished(): void
    {
        $document = self::map(Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml'));
        $paths = self::map($document['paths'] ?? null);

        self::assertSame('listInstruments', self::map(self::map($paths['/api/v1/instruments'] ?? null)['get'] ?? null)['operationId'] ?? null);
        self::assertSame('createInstrument', self::map(self::map($paths['/api/v1/instruments'] ?? null)['post'] ?? null)['operationId'] ?? null);
        self::assertSame('readInstrument', self::map(self::map($paths['/api/v1/instruments/{id}'] ?? null)['get'] ?? null)['operationId'] ?? null);
        self::assertSame('updateInstrument', self::map(self::map($paths['/api/v1/instruments/{id}'] ?? null)['put'] ?? null)['operationId'] ?? null);
        self::assertSame('deactivateInstrument', self::map(self::map($paths['/api/v1/instruments/{id}/deactivate'] ?? null)['post'] ?? null)['operationId'] ?? null);
        self::assertSame('reactivateInstrument', self::map(self::map($paths['/api/v1/instruments/{id}/reactivate'] ?? null)['post'] ?? null)['operationId'] ?? null);

        $schemas = self::map(self::map($document['components'] ?? null)['schemas'] ?? null);
        $instrument = self::map($schemas['Instrument'] ?? null);
        self::assertSame([
            'id', 'name', 'isin', 'ticker', 'venueMic', 'instrumentType', 'assetClass', 'region',
            'currencyCode', 'quantityPrecision', 'pricePrecision', 'source', 'reviewed', 'reviewedAt',
            'status', 'used', 'version',
        ], $instrument['required'] ?? null);
        self::assertArrayNotHasKey('price', self::map($instrument['properties'] ?? null));

        $request = self::map(self::map($schemas['CreateInstrumentRequest'] ?? null)['properties'] ?? null);
        self::assertSame(6, self::map($request['quantityPrecision'] ?? null)['default'] ?? null);
        self::assertSame(4, self::map($request['pricePrecision'] ?? null)['default'] ?? null);
        self::assertSame(12, self::map($request['quantityPrecision'] ?? null)['maximum'] ?? null);

        $problem = self::map(self::extension($schemas['InstrumentProblem'] ?? null));
        self::assertArrayHasKey('existingId', $problem);
        self::assertSame(5, self::map($problem['candidates'] ?? null)['maxItems'] ?? null);
    }

    /**
     * Properties added by a specialised problem on top of the shared `Problem` it composes with allOf.
     *
     * @return array<string, mixed>
     */
    private static function extension(mixed $schema): array
    {
        $parts = self::map($schema)['allOf'] ?? null;
        self::assertIsArray($parts);

        return self::map(self::map($parts[1] ?? null)['properties'] ?? null);
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
