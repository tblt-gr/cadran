<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Rules 2 to 5 of the API contract review. The test reads the OpenAPI document only: it
 * guards the shape of the contract, while the functional suites guard the behaviour.
 */
final class OpenApiConventionsContractTest extends TestCase
{
    private const array HTTP_METHODS = ['get', 'post', 'put', 'patch', 'delete'];
    private const array MUTATIONS = ['post', 'put', 'patch', 'delete'];
    private const array FORBIDDEN_PAGINATION = ['limit', 'offset', 'perPage'];

    /** @var array<string, mixed>|null */
    private static ?array $document = null;

    public function testEveryOperationDocumentsAProblemDetailsClientError(): void
    {
        $missing = [];
        foreach (self::operations() as $id => $operation) {
            if (!self::documentsProblemClientError($operation['operation'])) {
                $missing[] = $id;
            }
        }

        self::assertSame([], $missing, 'Operations without a 4xx application/problem+json response based on Problem.');
    }

    public function testEveryErrorBodySchemaIsBasedOnTheSharedProblem(): void
    {
        $violations = [];
        $responses = array_map(static fn (mixed $response): array => self::map($response), self::map(self::map(self::document()['components'] ?? [])['responses'] ?? []));
        foreach (self::operations() as $id => $operation) {
            foreach (self::map($operation['operation']['responses'] ?? []) as $status => $response) {
                $responses[$id.' '.$status] = self::resolve(self::map($response));
            }
        }
        foreach ($responses as $name => $response) {
            $content = self::map(self::map($response['content'] ?? [])['application/problem+json'] ?? []);
            if ([] !== $content && !self::isProblem(self::map($content['schema'] ?? []))) {
                $violations[] = $name;
            }
        }

        self::assertSame([], array_values(array_unique($violations)), 'application/problem+json responses whose schema is not Problem or composed with it.');
    }

    public function testPaginationUsesTheSharedVocabularyAndBounds(): void
    {
        $violations = [];
        foreach (self::operations() as $id => $operation) {
            $parameters = self::parameters($operation['path'], $operation['operation']);
            foreach ($parameters as $parameter) {
                if ('query' !== ($parameter['in'] ?? null)) {
                    continue;
                }
                $name = self::text($parameter['name'] ?? '');
                $schema = self::map($parameter['schema'] ?? []);
                if (in_array($name, self::FORBIDDEN_PAGINATION, true)) {
                    $violations[] = sprintf('%s: forbidden query parameter "%s"', $id, $name);
                }
                if ('pageSize' === $name && !self::isBoundedPageSize($schema)) {
                    $violations[] = sprintf('%s: pageSize needs minimum 1, maximum 100 and a default', $id);
                }
                if ('page' === $name && 1 !== ($schema['minimum'] ?? null)) {
                    $violations[] = sprintf('%s: page needs minimum 1', $id);
                }
                if ('cursor' === $name && !isset($schema['maxLength'])) {
                    $violations[] = sprintf('%s: cursor needs maxLength', $id);
                }
            }
            if ('get' === $operation['method']) {
                $names = array_map(static fn (array $parameter): string => self::text($parameter['name'] ?? ''), $parameters);
                $bounded = in_array('pageSize', $names, true)
                    || (isset($operation['operation']['x-cadran-bounded']) && '' !== trim(self::text($operation['operation']['x-cadran-bounded'])));
                $unbounded = $bounded ? [] : self::unboundedArrays($operation['operation']);
                if ([] !== $unbounded) {
                    $violations[] = sprintf('%s: arrays without maxItems (%s); add maxItems, pageSize or x-cadran-bounded', $id, implode(', ', array_slice($unbounded, 0, 3)));
                }
            }
        }

        self::assertSame([], $violations);
    }

    public function testNoNumberTypeAndTemporalFieldsCarryAFormat(): void
    {
        $violations = [];
        self::walk(self::document(), '#', static function (string $pointer, array $node) use (&$violations): void {
            if ('number' === ($node['type'] ?? null) || in_array($node['format'] ?? null, ['float', 'double'], true)) {
                $violations[] = $pointer.': floating point type';
            }
        });
        foreach (self::schemas() as $name => $schema) {
            foreach (self::map($schema['properties'] ?? []) as $property => $definition) {
                $definition = self::map($definition);
                if ('string' === ($definition['type'] ?? null)
                    && 1 === preg_match('/(Date|At)$|^date$/', (string) $property)
                    && !in_array($definition['format'] ?? null, ['date', 'date-time'], true)) {
                    $violations[] = sprintf('%s.%s: temporal string without date or date-time format', $name, $property);
                }
            }
        }

        self::assertSame([], $violations);
    }

    public function testEveryMoneyDecimalIsPairedWithItsAssetCode(): void
    {
        $schemas = self::schemas();
        $referrers = [];
        foreach ($schemas as $name => $schema) {
            self::walk($schema, '#', static function (string $pointer, array $node) use (&$referrers, $name): void {
                if (isset($node['$ref']) && is_string($node['$ref']) && str_starts_with($node['$ref'], '#/components/schemas/')) {
                    $referrers[substr($node['$ref'], strlen('#/components/schemas/'))][$name] = true;
                }
            });
        }

        $violations = [];
        foreach ($schemas as $name => $schema) {
            foreach (self::map($schema['properties'] ?? []) as $property => $definition) {
                if (!self::isDecimalString(self::resolve(self::map($definition)), (string) $property)) {
                    continue;
                }
                if (self::isUnitless((string) $property)
                    || self::carriesAsset($name, $schemas, $referrers, [])
                    || self::hasAssetScope($schema)) {
                    continue;
                }
                $violations[] = sprintf('%s.%s', $name, $property);
            }
        }

        self::assertSame([], $violations, 'Money decimals with no asset code in their schema or in a schema that contains it; carry the asset code or state its source in x-cadran-asset-scope.');
    }

    /**
     * A decimal is paired with its asset when its schema, or a schema that embeds it, holds an asset code member.
     *
     * @param array<string, array<string, mixed>> $schemas
     * @param array<string, array<string, true>>  $referrers
     * @param array<string, true>                 $seen
     */
    private static function carriesAsset(string $name, array $schemas, array $referrers, array $seen): bool
    {
        if (isset($seen[$name])) {
            return false;
        }
        $seen[$name] = true;
        foreach (array_keys(self::map($schemas[$name]['properties'] ?? [])) as $key) {
            if (1 === preg_match('/(^|[a-z])(AssetCode|CurrencyCode)$|^(assetCode|currencyCode|asset|currency)$/', (string) $key)) {
                return true;
            }
        }
        foreach (array_keys($referrers[$name] ?? []) as $parent) {
            if (self::carriesAsset($parent, $schemas, $referrers, $seen) || self::hasAssetScope($schemas[$parent])) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $schema */
    private static function hasAssetScope(array $schema): bool
    {
        return isset($schema['x-cadran-asset-scope']) && '' !== trim(self::text($schema['x-cadran-asset-scope']));
    }

    public function testIntegersAreCountsVersionsYearsAndPrecisions(): void
    {
        $violations = [];
        foreach (self::schemas() as $name => $schema) {
            foreach (self::map($schema['properties'] ?? []) as $property => $definition) {
                $definition = self::map($definition);
                $integer = 'integer' === ($definition['type'] ?? null)
                    || 'integer' === (self::map($definition['items'] ?? [])['type'] ?? null);
                if ($integer && !self::isCountVersionYearOrPrecision((string) $property)) {
                    $violations[] = sprintf('%s.%s', $name, $property);
                }
            }
        }

        self::assertSame([], $violations, 'Integer properties whose name is not a count, version, year, month, precision or rank.');
    }

    public function testEveryMutationDeclaresCsrfAndAReplayStrategy(): void
    {
        $violations = [];
        foreach (self::operations() as $id => $operation) {
            if (!in_array($operation['method'], self::MUTATIONS, true)) {
                continue;
            }
            $parameters = self::rawParameters($operation['operation']);
            $names = array_map(static fn (array $parameter): string => self::text($parameter['name'] ?? ''), self::parameters($operation['path'], $operation['operation']));
            $csrf = in_array('#/components/parameters/CsrfToken', array_column($parameters, '$ref'), true);
            if (!$csrf) {
                $violations[] = $id.': no CsrfToken parameter';
            }
            $replay = in_array('Idempotency-Key', $names, true)
                || self::requestHasVersion($operation['operation'])
                || (isset($operation['operation']['x-cadran-idempotency']) && '' !== trim(self::text($operation['operation']['x-cadran-idempotency'])));
            if (!$replay) {
                $violations[] = $id.': no Idempotency-Key, version field or x-cadran-idempotency';
            }
        }

        self::assertSame([], $violations);
    }

    /** @param array<string, mixed> $schema */
    private static function isDecimalString(array $schema, string $property): bool
    {
        $type = $schema['type'] ?? null;
        $isString = 'string' === $type || (is_array($type) && in_array('string', $type, true));
        if (!$isString || isset($schema['enum']) || isset($schema['const']) || isset($schema['format']) || isset($schema['maxLength'])) {
            return false;
        }
        if (isset($schema['pattern'])) {
            // A decimal shape: an optional fraction written with a literal dot.
            return str_contains(self::text($schema['pattern']), '\\.');
        }

        if (in_array('reason', self::words($property), true)) {
            return false;
        }

        return 1 === preg_match('/(amount|price|quantity|balance|value|total|cost|fee|gain|income|expense|saving|average|median|minimum|maximum)/i', $property);
    }

    /** Counts, versions, years, months, precisions, ranks and the HTTP status of a problem, matched on whole camelCase words. */
    private static function isCountVersionYearOrPrecision(string $property): bool
    {
        if (in_array($property, ['from', 'to', 'status'], true)) {
            return true;
        }
        $allowed = ['count', 'total', 'version', 'versions', 'year', 'month', 'months', 'precision', 'rank', 'order', 'sequence', 'priority', 'position', 'depth', 'level', 'size', 'page', 'day', 'days', 'sign', 'matched', 'examined', 'created', 'booked', 'changed', 'change', 'skipped', 'generated', 'index', 'length', 'decimals', 'digits', 'maximum', 'minimum'];

        return [] !== array_intersect(self::words($property), $allowed);
    }

    /** @return list<string> */
    private static function words(string $name): array
    {
        return array_map('strtolower', preg_split('/(?=[A-Z])/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: []);
    }

    /** Ratios, rates, shares, month counts and instrument quantities are not money: no asset code applies. */
    private static function isUnitless(string $property): bool
    {
        $unitless = ['ratio', 'rate', 'share', 'percent', 'percentage', 'weight', 'multiplier', 'months', 'fraction', 'factor', 'coefficient', 'quantity', 'numerator', 'denominator'];

        return [] !== array_intersect(self::words($property), $unitless);
    }

    /** @param array<string, mixed> $schema */
    private static function isBoundedPageSize(array $schema): bool
    {
        return 1 === ($schema['minimum'] ?? null) && 100 === ($schema['maximum'] ?? null) && isset($schema['default']);
    }

    /** @param array<string, mixed> $operation */
    private static function documentsProblemClientError(array $operation): bool
    {
        foreach (self::map($operation['responses'] ?? []) as $status => $response) {
            if (!str_starts_with((string) $status, '4')) {
                continue;
            }
            $response = self::resolve(self::map($response));
            $content = self::map(self::map($response['content'] ?? [])['application/problem+json'] ?? []);
            if ([] !== $content && self::isProblem(self::map($content['schema'] ?? []))) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $schema */
    private static function isProblem(array $schema): bool
    {
        if ('#/components/schemas/Problem' === ($schema['$ref'] ?? null)) {
            return true;
        }
        if (isset($schema['$ref'])) {
            return self::isProblem(self::resolve($schema));
        }
        foreach (self::list($schema['allOf'] ?? []) as $part) {
            if (self::isProblem(self::map($part))) {
                return true;
            }
        }
        foreach (['oneOf', 'anyOf'] as $keyword) {
            $alternatives = self::list($schema[$keyword] ?? []);
            if ([] !== $alternatives && [] === array_filter($alternatives, static fn (mixed $part): bool => !self::isProblem(self::map($part)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Pointers of the arrays, at any depth of the 2xx JSON response, that declare no maxItems.
     *
     * @param array<string, mixed> $operation
     *
     * @return list<string>
     */
    private static function unboundedArrays(array $operation): array
    {
        $found = [];
        foreach (self::map($operation['responses'] ?? []) as $status => $response) {
            if (!str_starts_with((string) $status, '2')) {
                continue;
            }
            $response = self::resolve(self::map($response));
            $schema = self::map(self::map(self::map($response['content'] ?? [])['application/json'] ?? [])['schema'] ?? []);
            self::collectUnbounded($schema, '#', [], $found);
        }

        return $found;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<string, true>  $visiting
     * @param list<string>         $found
     */
    private static function collectUnbounded(array $schema, string $pointer, array $visiting, array &$found): void
    {
        if (isset($schema['$ref'])) {
            $reference = self::text($schema['$ref']);
            if (isset($visiting[$reference])) {
                return;
            }
            $visiting[$reference] = true;
            self::collectUnbounded(self::resolve($schema), $reference, $visiting, $found);

            return;
        }
        $type = $schema['type'] ?? null;
        if ('array' === $type || (is_array($type) && in_array('array', $type, true))) {
            if (!isset($schema['maxItems'])) {
                $found[] = $pointer;
            }
            self::collectUnbounded(self::map($schema['items'] ?? []), $pointer.'[]', $visiting, $found);
        }
        foreach (self::map($schema['properties'] ?? []) as $name => $property) {
            self::collectUnbounded(self::map($property), $pointer.'.'.$name, $visiting, $found);
        }
        foreach (['allOf', 'oneOf', 'anyOf'] as $keyword) {
            foreach (self::list($schema[$keyword] ?? []) as $part) {
                self::collectUnbounded(self::map($part), $pointer, $visiting, $found);
            }
        }
        if (isset($schema['additionalProperties']) && is_array($schema['additionalProperties'])) {
            self::collectUnbounded(self::map($schema['additionalProperties']), $pointer.'{}', $visiting, $found);
        }
    }

    /** @param array<string, mixed> $operation */
    private static function requestHasVersion(array $operation): bool
    {
        $request = self::resolve(self::map($operation['requestBody'] ?? []));
        $schema = self::resolve(self::map(self::map(self::map($request['content'] ?? [])['application/json'] ?? [])['schema'] ?? []));

        return self::hasVersionProperty($schema);
    }

    /** @param array<string, mixed> $schema */
    private static function hasVersionProperty(array $schema): bool
    {
        if (array_key_exists('version', self::map($schema['properties'] ?? []))) {
            return true;
        }
        foreach (['allOf', 'oneOf', 'anyOf'] as $keyword) {
            foreach (self::list($schema[$keyword] ?? []) as $part) {
                if (self::hasVersionProperty(self::resolve(self::map($part)))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return list<array<string, mixed>>
     */
    private static function rawParameters(array $operation): array
    {
        return array_map(static fn (mixed $parameter): array => self::map($parameter), self::list($operation['parameters'] ?? []));
    }

    /**
     * @param array<string, mixed> $operation
     *
     * @return list<array<string, mixed>>
     */
    private static function parameters(string $path, array $operation): array
    {
        $parameters = self::rawParameters(self::map(self::map(self::document()['paths'] ?? [])[$path] ?? []));

        return array_map(static fn (array $parameter): array => self::resolve($parameter), [...$parameters, ...self::rawParameters($operation)]);
    }

    /** @return array<string, array{method: string, path: string, operation: array<string, mixed>}> */
    private static function operations(): array
    {
        $operations = [];
        foreach (self::map(self::document()['paths'] ?? []) as $path => $item) {
            foreach (self::map($item) as $method => $operation) {
                if (in_array($method, self::HTTP_METHODS, true)) {
                    $operations[strtoupper((string) $method).' '.$path] = [
                        'method' => (string) $method,
                        'path' => (string) $path,
                        'operation' => self::map($operation),
                    ];
                }
            }
        }

        return $operations;
    }

    /** @return array<string, array<string, mixed>> */
    private static function schemas(): array
    {
        return array_map(static fn (mixed $schema): array => self::map($schema), self::map(self::map(self::document()['components'] ?? [])['schemas'] ?? []));
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<string, mixed>
     */
    private static function resolve(array $node): array
    {
        for ($depth = 0; isset($node['$ref']) && $depth < 10; ++$depth) {
            $pointer = self::text($node['$ref']);
            self::assertStringStartsWith('#/', $pointer);
            $target = self::document();
            foreach (explode('/', substr($pointer, 2)) as $segment) {
                $target = self::map($target)[$segment] ?? null;
            }
            $node = self::map($target);
        }

        return $node;
    }

    /** @param callable(string, array<string, mixed>): void $visit */
    private static function walk(mixed $node, string $pointer, callable $visit): void
    {
        if (!is_array($node)) {
            return;
        }
        if (!array_is_list($node)) {
            $visit($pointer, self::map($node));
        }
        foreach ($node as $key => $child) {
            self::walk($child, $pointer.'/'.$key, $visit);
        }
    }

    /** @return array<string, mixed> */
    private static function document(): array
    {
        return self::$document ??= self::map(Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml'));
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);

        $typed = [];
        foreach ($value as $key => $item) {
            $typed[(string) $key] = $item;
        }

        return $typed;
    }

    private static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values($value);
    }
}
