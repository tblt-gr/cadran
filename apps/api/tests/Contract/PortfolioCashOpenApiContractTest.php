<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Portfolio\Application\PortfolioCashView;
use App\Module\Portfolio\Domain\Cash\CashLedger;
use App\Module\Portfolio\Domain\Cash\CashWarning;
use App\Module\Portfolio\Domain\Cash\CashWarningCode;
use App\Module\Portfolio\UI\Http\PortfolioCashRepresentation;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PortfolioCashOpenApiContractTest extends TestCase
{
    public function testTheCashSchemaDeclaresExactlyTheMembersTheRepresentationEmits(): void
    {
        $warning = CashWarning::of(CashWarningCode::ROUNDING_RESIDUALS, ['00000000-0000-7000-8000-000000000001'], null, DecimalValue::fromString('0.002'));
        $payload = PortfolioCashRepresentation::of(new PortfolioCashView(
            '2026-03-01', '2026-03-31', 'EUR', DecimalValue::zero(), CashLedger::replay([], []), [$warning],
        ));

        $cash = $this->schema('PortfolioCash');
        self::assertFalse($cash['additionalProperties'] ?? true);
        self::assertEqualsCanonicalizing(array_keys($payload), self::list($cash['required'] ?? null));
        self::assertEqualsCanonicalizing(array_keys($payload), array_keys(self::map($cash['properties'] ?? null)));
        $totals = self::map($payload['totals']);
        self::assertEqualsCanonicalizing(array_keys($totals), self::list($this->schema('PortfolioCashTotals')['required'] ?? null));
        $warnings = $payload['warnings'];
        self::assertIsArray($warnings);
        self::assertEqualsCanonicalizing(array_keys(self::map($warnings[0])), self::list($this->schema('PortfolioCashWarning')['required'] ?? null));
        self::assertEqualsCanonicalizing(array_keys(self::map($payload['costPolicy'])), self::list($this->schema('PortfolioCostPolicy')['required'] ?? null));
        self::assertSame(
            ['NEGATIVE_CASH', 'UNLINKED_TRANSFER_IN', 'UNLINKED_TRANSFER_OUT', 'UNLINKED_EXTERNAL_FLOW', 'LINKED_TRANSFER_CHANGED', 'NON_TRANSFER_TRANSACTION', 'ROUNDING_RESIDUALS'],
            self::list(self::map(self::map($this->schema('PortfolioCashWarning')['properties'] ?? null)['code'] ?? null)['enum'] ?? null),
        );
    }

    public function testTheMonthlyRecapRequiresItsNullablePortfolioCostsBlock(): void
    {
        $recap = $this->schema('MonthlyRecap');
        self::assertContains('portfolioCosts', self::list($recap['required'] ?? null));
        $costs = self::map(self::map($recap['properties'] ?? null)['portfolioCosts'] ?? null);
        self::assertSame(
            [['$ref' => '#/components/schemas/MonthlyPortfolioCosts'], ['type' => 'null']],
            $costs['oneOf'] ?? null,
        );
        self::assertEqualsCanonicalizing(['items', 'totalFees', 'totalTaxes', 'assetCode'], self::list($this->schema('MonthlyPortfolioCosts')['required'] ?? null));
    }

    public function testThePathDeclaresItsQueryParametersAndBoundedProblems(): void
    {
        $paths = self::map($this->document()['paths'] ?? null);
        $get = self::map(self::map($paths['/api/v1/accounts/{accountId}/portfolio-cash'] ?? null)['get'] ?? null);

        self::assertSame('readPortfolioCash', $get['operationId'] ?? null);
        $names = array_map(static fn (mixed $parameter): mixed => is_array($parameter) ? ($parameter['name'] ?? null) : null, self::list($get['parameters'] ?? null));
        self::assertContains('from', $names);
        self::assertContains('to', $names);
        $codes = self::list(self::map(self::map($this->schema('PortfolioOperationProblem')['properties'] ?? null)['code'] ?? null)['enum'] ?? null);
        foreach (['invalid_cash_query', 'cash_range_too_large', 'cash_range_too_dense', 'cash_amount_too_large'] as $code) {
            self::assertContains($code, $codes);
        }
        self::assertSame('portfolio_account_requires_operations', self::map(self::map($this->schema('PortfolioAccountRequiresOperationsProblem')['properties'] ?? null)['code'] ?? null)['const'] ?? null);
    }

    /** @return array<string, mixed> */
    private function schema(string $name): array
    {
        $schemas = self::map(self::map($this->document()['components'] ?? null)['schemas'] ?? null);

        return self::map($schemas[$name] ?? null);
    }

    /** @return array<string, mixed> */
    private function document(): array
    {
        return self::map(Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml'));
    }

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        self::assertIsList($value);

        return $value;
    }

    /** @return array<string, mixed> */
    private static function map(mixed $value): array
    {
        self::assertIsArray($value);
        $map = [];
        foreach ($value as $key => $item) {
            self::assertIsString($key);
            $map[$key] = $item;
        }

        return $map;
    }
}
