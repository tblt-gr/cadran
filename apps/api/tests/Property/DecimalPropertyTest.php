<?php

declare(strict_types=1);

namespace App\Tests\Property;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionSplit;
use App\Module\Transactions\Domain\TransactionState;
use App\Module\Transactions\Domain\Transfer;
use App\Tests\Support\DecimalGenerator;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

/**
 * Seeded generated-input checks of the decimal invariants. Replay a failure with
 * PROPERTY_SEED=<seed>; PROPERTY_CASES=<n> changes the number of cases.
 */
final class DecimalPropertyTest extends TestCase
{
    // Operands carry at most 25 integer digits so that sums stay within the 26 digit range.
    private const int OPERAND_DIGITS = 25;

    public function testSubtractingTheAddendRestoresTheOperand(): void
    {
        $this->forAll(static function (DecimalGenerator $g): array {
            [$a, $b] = [$g->decimal(self::OPERAND_DIGITS), $g->decimal(self::OPERAND_DIGITS)];

            return [[$a, $b], static function () use ($a, $b): void {
                $restored = ExactDecimal::subtract(ExactDecimal::add(DecimalValue::fromString($a), DecimalValue::fromString($b)), DecimalValue::fromString($b));
                self::assertSame(0, $restored->compareTo(DecimalValue::fromString($a)));
            }];
        });
    }

    public function testSumIsOrderIndependentAndMatchesPairwiseAddition(): void
    {
        $this->forAll(static function (DecimalGenerator $g): array {
            [$a, $b, $c] = [$g->decimal(self::OPERAND_DIGITS), $g->decimal(self::OPERAND_DIGITS), $g->decimal(self::OPERAND_DIGITS)];

            return [[$a, $b, $c], static function () use ($a, $b, $c): void {
                [$x, $y, $z] = [DecimalValue::fromString($a), DecimalValue::fromString($b), DecimalValue::fromString($c)];
                $sum = ExactDecimal::sum($x, $y, $z);
                self::assertSame(0, $sum->compareTo(ExactDecimal::sum($z, $x, $y)));
                self::assertSame(0, $sum->compareTo(ExactDecimal::add(ExactDecimal::add($x, $y), $z)));
            }];
        });
    }

    public function testNegationIsAnInvolutionAndCancelsItsOperand(): void
    {
        $this->forAll(static function (DecimalGenerator $g): array {
            $a = $g->decimal();

            return [[$a], static function () use ($a): void {
                $value = DecimalValue::fromString($a);
                self::assertSame(0, ExactDecimal::negate(ExactDecimal::negate($value))->compareTo($value));
                self::assertTrue(ExactDecimal::isZero(ExactDecimal::add($value, ExactDecimal::negate($value))));
            }];
        });
    }

    public function testSplitsSummingToTheAmountAreAcceptedAndOneUnitOffIsRefused(): void
    {
        $this->forAll(static function (DecimalGenerator $g): array {
            $count = $g->integer(2, 10);
            $amounts = [];
            for ($index = 0; $index < $count; ++$index) {
                $amounts[] = $g->positive(self::OPERAND_DIGITS);
            }

            return [$amounts, static function () use ($amounts): void {
                $total = ExactDecimal::sum(...array_map(DecimalValue::fromString(...), $amounts));
                self::transaction($total, $amounts);

                $off = $amounts;
                $off[0] = ExactDecimal::add(DecimalValue::fromString($off[0]), DecimalValue::fromString('0.000000000000000000000001'))->toString();
                try {
                    self::transaction($total, $off);
                } catch (InvalidTransaction) {
                    return;
                }
                self::fail('Splits one unit off the amount were accepted.');
            }];
        });
    }

    public function testTransferLegsOfOneAssetOppose(): void
    {
        $this->forAll(static function (DecimalGenerator $g): array {
            $a = $g->positive();

            return [[$a], static function () use ($a): void {
                $asset = AssetCode::fromString('EUR');
                $target = new AssetAmount(DecimalValue::fromString($a), $asset);
                $source = new AssetAmount(ExactDecimal::negate($target->value), $asset);
                Transfer::assertOpposition($source, $target);
                self::assertTrue(ExactDecimal::isZero(ExactDecimal::add($source->value, $target->value)));
            }];
        });
    }

    /**
     * Each case runs its own check; a failure reports the seed, case index and inputs.
     *
     * @param callable(DecimalGenerator): array{0: list<string>, 1: callable(): void} $property
     */
    private function forAll(callable $property): void
    {
        $seed = DecimalGenerator::seed();
        $generator = new DecimalGenerator($seed);
        $cases = DecimalGenerator::cases();
        for ($index = 0; $index < $cases; ++$index) {
            [$inputs, $check] = $property($generator);
            try {
                $check();
            } catch (\Throwable $failure) {
                self::fail(sprintf(
                    'Property failed (PROPERTY_SEED=%d, case %d, inputs %s): %s',
                    $seed, $index, json_encode($inputs, JSON_THROW_ON_ERROR), $failure->getMessage(),
                ));
            }
        }
        $this->addToAssertionCount(1);
    }

    /** @param list<string> $splitAmounts positive amounts of an income transaction */
    private static function transaction(DecimalValue $amount, array $splitAmounts): Transaction
    {
        $id = '00000000-0000-7000-8000-0000000000f1';
        $workspace = WorkspaceFixture::own();
        $asset = AssetCode::fromString('EUR');
        $now = new \DateTimeImmutable('2026-03-14T09:12:04+00:00');
        $splits = [];
        foreach ($splitAmounts as $position => $value) {
            $splits[] = new TransactionSplit(
                sprintf('00000000-0000-7000-8000-%012x', 0x100 + $position), $workspace, $id,
                sprintf('00000000-0000-7000-8000-%012x', 0x200 + $position),
                new AssetAmount(DecimalValue::fromString($value), $asset), [], null, $now, $position,
            );
        }

        return new Transaction(
            id: $id, workspace: $workspace, accountId: '00000000-0000-7000-8000-0000000000d1',
            amount: new AssetAmount($amount, $asset), originalAmount: null, exchangeRate: null,
            state: TransactionState::BOOKED, nature: TransactionNature::INCOME, source: TransactionSource::MANUAL,
            sourceRef: null, bookedOn: new \DateTimeImmutable('2026-03-14'), valueOn: null, authorizedOn: null,
            rawLabel: 'Generated', counterparty: null, note: null, paymentMethod: null, mcc: null,
            maskedCard: null, bankReference: null, splits: $splits, version: 1, createdAt: $now,
            updatedAt: $now, voidedAt: null, lastEditorId: WorkspaceFixture::OWNER_ID,
        );
    }
}
