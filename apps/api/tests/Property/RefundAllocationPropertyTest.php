<?php

declare(strict_types=1);

namespace App\Tests\Property;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Application\RefundAllocation;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionSplit;
use App\Module\Transactions\Domain\TransactionState;
use App\Tests\Support\DecimalGenerator;
use App\Tests\Support\WorkspaceFixture;
use Brick\Math\BigInteger;
use PHPUnit\Framework\TestCase;

/**
 * Generated cent amounts against the refund proposal. Replay a failure with
 * PROPERTY_SEED=<seed>; PROPERTY_CASES=<n> changes the number of cases.
 */
final class RefundAllocationPropertyTest extends TestCase
{
    public function testAProposalSumsToTheRefundWithinEachSplitAndGivesTiesToTheLowestPosition(): void
    {
        $generator = new DecimalGenerator($seed = DecimalGenerator::seed());
        for ($case = 0; $case < DecimalGenerator::cases(); ++$case) {
            // Small magnitudes make equal remainders frequent, large ones exercise exactness.
            $magnitude = 0 === $generator->integer(0, 1) ? 4 : 1_000_000_000_000;
            $splits = [];
            $total = 0;
            for ($index = 0, $count = $generator->integer(1, 6); $index < $count; ++$index) {
                $total += $splits[] = $generator->integer(1, $magnitude);
            }
            $refund = $generator->integer(1, $total);
            $message = sprintf('seed %d, case %d: refund %d of %s', $seed, $case, $refund, implode('+', $splits));

            $proposed = [];
            foreach ((new RefundAllocation())->propose(self::original($splits), self::cents($refund), 2) as $row) {
                $proposed[$row['categoryId']] = (int) str_replace('.', '', $row['amount']->value->toString());
            }

            self::assertSame($refund, array_sum($proposed), $message);
            $extra = $remainders = [];
            foreach ($splits as $index => $split) {
                $part = $proposed[self::category($index)] ?? 0;
                self::assertLessThanOrEqual($split, $part, $message);
                [$floor, $remainder] = BigInteger::of($refund)->multipliedBy($split)->quotientAndRemainder($total);
                $extra[$index] = $part - $floor->toInt();
                $remainders[$index] = $remainder->toInt();
                self::assertContains($extra[$index], [0, 1], $message);
                for ($earlier = 0; $earlier < $index; ++$earlier) {
                    if ($remainders[$earlier] === $remainders[$index]) {
                        self::assertGreaterThanOrEqual($extra[$index], $extra[$earlier], $message);
                    }
                }
            }
        }
    }

    /** @param list<int> $splits */
    private static function original(array $splits): Transaction
    {
        $now = new \DateTimeImmutable('2026-03-14T09:12:04+00:00');
        $rows = [];
        foreach ($splits as $index => $cents) {
            $rows[] = new TransactionSplit(
                sprintf('00000000-0000-7000-8000-000000000a%02d', $index), WorkspaceFixture::own(), '00000000-0000-7000-8000-0000000000f1',
                self::category($index), self::cents(-$cents), [], null, $now, $index,
            );
        }

        return new Transaction(
            '00000000-0000-7000-8000-0000000000f1', WorkspaceFixture::own(), '00000000-0000-7000-8000-0000000000d1',
            self::cents(-array_sum($splits)), null, null, TransactionState::BOOKED, TransactionNature::EXPENSE, TransactionSource::MANUAL,
            null, new \DateTimeImmutable('2026-03-14'), null, null, 'Original', null, null, null, null, null,
            null, $rows, 1, $now, $now, null, null,
        );
    }

    private static function category(int $index): string
    {
        return sprintf('00000000-0000-7000-8000-000000000c%02d', $index);
    }

    private static function cents(int $cents): AssetAmount
    {
        $literal = sprintf('%s%d.%02d', $cents < 0 ? '-' : '', intdiv(abs($cents), 100), abs($cents) % 100);

        return new AssetAmount(DecimalValue::fromString($literal), AssetCode::fromString('EUR'));
    }
}
