<?php

declare(strict_types=1);

namespace App\Tests\Contract;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class PortfolioPerformanceOpenApiContractTest extends TestCase
{
    public function testThePerformanceReadContractIsBoundedExactAndNeverZeroByDefault(): void
    {
        $document = self::map(Yaml::parseFile(dirname(__DIR__, 2).'/openapi/openapi.yaml'));
        $get = self::map(self::map(self::map($document['paths'])['/api/v1/accounts/{accountId}/portfolio-performance'])['get']);
        self::assertSame('readPortfolioPerformance', $get['operationId']);
        self::assertSame([['sessionCookie' => []]], $get['security']);
        $responses = $get['responses'];
        self::assertIsArray($responses);
        foreach (['200', '401', '403', '404', '422', '500'] as $status) {
            self::assertArrayHasKey((int) $status, $responses);
        }
        $parameters = $get['parameters'];
        self::assertIsArray($parameters);
        $scope = self::map($parameters[1]);
        self::assertSame(['scope', 'query', true], [$scope['name'], $scope['in'], $scope['required']]);
        self::assertSame(['MONTH', 'YEAR', 'INCEPTION'], self::map($scope['schema'])['enum']);

        $schemas = self::map(self::map($document['components'])['schemas']);
        $performance = self::map($schemas['PortfolioPerformance']);
        self::assertSame(
            ['accountId', 'assetCode', 'scope', 'period', 'methodVersion', 'costPolicy', 'start', 'end', 'flows', 'flowCount', 'netFlows', 'gain', 'modifiedDietz', 'timeWeighted'],
            $performance['required'],
        );
        self::assertSame(100, self::map(self::map($performance['properties'])['flows'])['maxItems']);
        $figure = self::map($schemas['PortfolioGainFigure']);
        self::assertSame(50, self::map(self::map($figure['properties'])['sources'])['maxItems']);
        self::assertContains('decomposition', self::list($figure['required']));
        $amount = self::map($schemas['PortfolioPerformanceAmount']);
        self::assertSame(['string', 'null'], $amount['type']);
        $decomposition = self::map(self::map($schemas['PortfolioGainDecomposition'])['properties']);
        foreach (['realized', 'unrealizedChange', 'income', 'fees', 'taxes', 'corporateActionCash', 'capitalizedCosts', 'residual'] as $component) {
            self::assertArrayHasKey($component, $decomposition);
        }
        $reasonCodes = self::list(self::map(self::map(self::map($schemas['PortfolioPerformanceReason'])['properties'])['code'])['enum']);
        foreach (['NO_INVESTED_CAPITAL', 'NON_POSITIVE_DENOMINATOR', 'DENOMINATOR_TOO_SMALL', 'MONTH_NOT_CALCULABLE', 'TOO_MANY_PERIODS'] as $code) {
            self::assertContains($code, $reasonCodes);
        }
        self::assertSame('#/components/schemas/PortfolioModifiedDietzFigure', self::map(self::map($performance['properties'])['modifiedDietz'])['$ref']);
        $dietz = self::map($schemas['PortfolioModifiedDietzFigure']);
        self::assertSame(['MODIFIED_DIETZ'], self::map(self::map($dietz['properties'])['method'])['enum']);
        self::assertNotContains('EXACT', self::list(self::map($schemas['PortfolioModifiedDietzQuality'])['enum']));
        self::assertSame(100, self::map(self::map($dietz['properties'])['weights'])['maxItems']);
        self::assertSame(600, self::map(self::list(self::map(self::map($dietz['properties'])['months'])['oneOf'])[0])['maxItems']);
        foreach (['value', 'averageCapital', 'minCapitalRatio', 'weights', 'weightCount', 'months'] as $field) {
            self::assertContains($field, self::list($dietz['required']));
        }
        $twr = self::map($schemas['PortfolioTimeWeightedFigure']);
        self::assertSame(['TWR'], self::map(self::map($twr['properties'])['method'])['enum']);
        self::assertSame(400, self::map(self::map($twr['properties'])['subperiods'])['maxItems']);
        foreach (['MISSING_FLOW_VALUATION', 'STALE_FLOW_VALUATION', 'ZERO_START_VALUE', 'TOO_MANY_SUBPERIODS'] as $code) {
            self::assertContains($code, $reasonCodes);
        }
        $problem = self::map(self::map(self::list(self::map($schemas['PortfolioPerformanceProblem'])['allOf'])[1])['properties']);
        foreach (['invalid_performance_scope', 'invalid_performance_period', 'account_not_portfolio', 'performance_range_too_dense'] as $code) {
            self::assertContains($code, self::list(self::map($problem['code'])['enum']));
        }
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

    /** @return list<mixed> */
    private static function list(mixed $value): array
    {
        self::assertIsArray($value);

        return array_values($value);
    }
}
