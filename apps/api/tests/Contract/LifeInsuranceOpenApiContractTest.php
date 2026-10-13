<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class LifeInsuranceOpenApiContractTest extends TestCase
{
    public function testEveryRouteIsDocumentedAndEveryMutationRequiresTheCsrfToken(): void
    {
        $paths = self::map(self::document()['paths'] ?? null);

        foreach ([
            ['/api/v1/accounts/{accountId}/life-insurance-contract', 'get', 'readLifeInsuranceContract'],
            ['/api/v1/accounts/{accountId}/life-insurance-contract', 'put', 'saveLifeInsuranceContract'],
            ['/api/v1/accounts/{accountId}/life-insurance-contract/fee-terms', 'post', 'addLifeInsuranceFeeTerms'],
            ['/api/v1/life-insurance-fee-terms/{id}', 'delete', 'removeLifeInsuranceFeeTerms'],
            ['/api/v1/accounts/{accountId}/life-insurance-contract/euro-fund-rates', 'post', 'recordEuroFundRate'],
            ['/api/v1/life-insurance-euro-fund-rates/{id}', 'delete', 'removeEuroFundRate'],
        ] as [$path, $method, $operationId]) {
            $operation = self::map(self::map($paths[$path] ?? null)[$method] ?? null);
            self::assertSame($operationId, $operation['operationId'] ?? null);
            $parameters = json_encode($operation['parameters'] ?? null, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            if ('get' === $method) {
                self::assertStringNotContainsString('CsrfToken', $parameters);
            } else {
                self::assertStringContainsString('#/components/parameters/CsrfToken', $parameters);
            }
        }
    }

    public function testNoSchemaCarriesABeneficiaryOrTheFullContractNumberInAResponse(): void
    {
        $schemas = self::map(self::map(self::document()['components'] ?? null)['schemas'] ?? null);

        $lifeInsurance = array_filter($schemas, static fn (string $name): bool => str_starts_with($name, 'LifeInsurance'), ARRAY_FILTER_USE_KEY);
        self::assertNotSame([], $lifeInsurance);
        self::assertStringNotContainsString('beneficiar', strtolower(json_encode($lifeInsurance, JSON_THROW_ON_ERROR)));

        $contract = self::map($schemas['LifeInsuranceContract'] ?? null);
        self::assertFalse($contract['additionalProperties'] ?? true);
        $properties = self::map($contract['properties'] ?? null);
        self::assertArrayHasKey('contractNumberMasked', $properties);
        self::assertArrayNotHasKey('contractNumber', $properties);

        $input = self::map($schemas['LifeInsuranceContractInput'] ?? null);
        self::assertFalse($input['additionalProperties'] ?? true);
        self::assertSame(['insurer', 'subscribedOn', 'managementMode'], $input['required'] ?? null);
        self::assertSame(34, self::map(self::map($input['properties'] ?? null)['contractNumber'] ?? null)['maxLength'] ?? null);
    }

    public function testTheProblemsPublishEveryCodeTheRoutesCanReturn(): void
    {
        $schemas = self::map(self::map(self::document()['components'] ?? null)['schemas'] ?? null);

        $codes = self::codes($schemas, 'LifeInsuranceProblem');
        foreach ([
            'account_not_life_insurance', 'invalid_contract_field', 'invalid_contract_date', 'invalid_fee_rate', 'invalid_fee_terms_date',
            'invalid_euro_fund_rate', 'instrument_not_euro_fund', 'life_insurance_contract_exists', 'stale_contract_version',
            'fee_terms_exist', 'fee_terms_limit_reached', 'euro_fund_rate_exists',
        ] as $code) {
            self::assertContains($code, $codes);
        }
        foreach (['life_insurance_contract_required', 'life_insurance_contract_closed', 'operation_type_not_allowed_for_account', 'euro_fund_price_fixed'] as $code) {
            self::assertContains($code, self::codes($schemas, 'PortfolioOperationProblem'));
        }
        self::assertContains('euro_fund_price_fixed', self::codes($schemas, 'PortfolioValuationProblem'));
        self::assertContains('instrument_field_not_allowed', self::codes($schemas, 'InstrumentProblem'));
        $types = self::map($schemas['InstrumentType'] ?? null)['enum'] ?? [];
        self::assertIsArray($types);
        self::assertContains('EURO_FUND', $types);
    }

    /**
     * @param array<string, mixed> $schemas
     *
     * @return array<mixed>
     */
    private static function codes(array $schemas, string $problem): array
    {
        $codes = self::map(self::map(self::extension($schemas[$problem] ?? null))['code'] ?? null)['enum'] ?? null;
        self::assertIsArray($codes);

        return $codes;
    }

    /** @return array<string, mixed> */
    private static function document(): array
    {
        return self::map(Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml'));
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
