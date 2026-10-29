<?php

declare(strict_types=1);

namespace App\Tests\Module\Reporting\Application;

use App\Module\Reporting\Application\AccountWeight;
use App\Module\Reporting\Application\MonthFigures;
use App\Module\Reporting\Application\MonthlyMetricView;
use App\Module\Reporting\Application\MonthlyRecapCategoryView;
use App\Tests\Module\Reporting\Application\Double\MonthlyProjectionFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MonthFiguresPayloadTest extends TestCase
{
    public function testAccountWeightsSurviveTheSnapshotPayloadExactly(): void
    {
        $weights = [
            new AccountWeight('a1', '-200.25', 'EUR', true),
            new AccountWeight('a2', null, null, true),
            new AccountWeight('a3', '99999999999999999999999999.999999999999999999999999', 'EUR', false),
        ];
        $figures = new MonthFigures('2026-03', 1, 'CURRENT', 0, '2026-03-31', ['endNetWorth' => new MonthlyMetricView('1', 'EUR', null)], [], [], $weights);

        $decoded = json_decode(json_encode($figures->toPayload(), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        $restored = MonthFigures::fromPayload($decoded);

        self::assertEquals($weights, $restored->accountWeights);
    }

    public function testACategoryColumnCoversItsDescendantsWhileTheOwnCellStaysApart(): void
    {
        $own = new MonthlyRecapCategoryView('parent', 'Alimentation', 'EXPENSE', new MonthlyMetricView(null, null, 'NO_MOVEMENTS'));
        $rollup = new MonthlyRecapCategoryView('parent', 'Alimentation', 'EXPENSE', new MonthlyMetricView('120', 'EUR', null));

        $figures = MonthFigures::fromProjection(MonthlyProjectionFixture::view('2025-03', categoryMetrics: [$own], categoryRollups: [$rollup]));

        self::assertSame('120', $figures->cells['category:parent']->value);
        self::assertNull($figures->cells[MonthFigures::OWN_CATEGORY_PREFIX.'parent']->value);
    }

    public function testAPayloadWrittenBeforeTheWeightsExistedReadsAsLegacy(): void
    {
        $payload = (new MonthFigures('2026-03', 1, 'CURRENT', 0, '2026-03-31', [], [], [], []))->toPayload();
        unset($payload['accountWeights']);

        self::assertNull(MonthFigures::fromPayload($payload)->accountWeights);
    }

    public function testAnEmptyListOfWeightsIsNotLegacy(): void
    {
        $payload = (new MonthFigures('2026-03', 1, 'CURRENT', 0, '2026-03-31', [], [], [], []))->toPayload();

        self::assertSame([], MonthFigures::fromPayload($payload)->accountWeights);
    }

    /** @param array<mixed> $weights */
    #[DataProvider('malformedWeights')]
    public function testMalformedWeightsAreRejected(mixed $weights): void
    {
        $payload = (new MonthFigures('2026-03', 1, 'CURRENT', 0, '2026-03-31', [], [], [], []))->toPayload();
        $payload['accountWeights'] = $weights;

        $this->expectException(\UnexpectedValueException::class);
        MonthFigures::fromPayload($payload);
    }

    /** @return iterable<string, array{mixed}> */
    public static function malformedWeights(): iterable
    {
        $valid = ['accountId' => 'a1', 'signedValue' => '10', 'assetCode' => 'EUR', 'includeInNetWorth' => true];

        yield 'not a list' => ['a1'];
        yield 'item not an object' => [['a1']];
        yield 'missing account id' => [[['signedValue' => '10', 'assetCode' => 'EUR', 'includeInNetWorth' => true]]];
        yield 'account id not text' => [[[...$valid, 'accountId' => 7]]];
        yield 'value not text' => [[[...$valid, 'signedValue' => 10]]];
        yield 'value not canonical' => [[[...$valid, 'signedValue' => '1e3']]];
        yield 'value beyond precision' => [[[...$valid, 'signedValue' => '1.0000000000000000000000001']]];
        yield 'negative zero' => [[[...$valid, 'signedValue' => '-0']]];
        yield 'asset not text' => [[[...$valid, 'assetCode' => 3]]];
        yield 'flag not boolean' => [[[...$valid, 'includeInNetWorth' => 'yes']]];
        yield 'flag missing' => [[['accountId' => 'a1', 'signedValue' => '10', 'assetCode' => 'EUR']]];
        yield 'value without asset' => [[[...$valid, 'assetCode' => null]]];
    }
}
