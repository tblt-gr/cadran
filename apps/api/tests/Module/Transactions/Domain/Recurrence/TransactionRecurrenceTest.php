<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Recurrence\InvalidRecurrence;
use App\Module\Transactions\Domain\Recurrence\RecurrenceIntervalKind;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransactionRecurrenceTest extends TestCase
{
    private const string WORKSPACE = '00000000-0000-7000-8000-0000000000a1';
    private const string RECURRENCE = '00000000-0000-7000-8000-0000000000e1';
    private const string ACCOUNT = '00000000-0000-7000-8000-0000000000d1';

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidRecurrences(): iterable
    {
        yield 'malformed identifier' => [['id' => 'not-a-uuid']];
        yield 'malformed account' => [['accountId' => 'not-a-uuid']];
        yield 'blank label' => [['label' => '']];
        yield 'untrimmed label' => [['label' => ' Netflix']];
        yield 'label beyond 80 characters' => [['label' => str_repeat('a', 81)]];
        yield 'blank counterparty' => [['counterparty' => '']];
        yield 'counterparty beyond 80 characters' => [['counterparty' => str_repeat('a', 81)]];
        yield 'zero expected amount' => [['expectedAmount' => '0.00']];
        yield 'negative tolerance' => [['amountTolerance' => '-0.01']];
        yield 'tolerance in another asset' => [['toleranceAsset' => 'USD']];
        yield 'day zero of a month' => [['dayOfPeriod' => 0]];
        yield 'day beyond the month' => [['dayOfPeriod' => 32]];
        yield 'weekday beyond the week' => [['intervalKind' => RecurrenceIntervalKind::WEEKLY, 'dayOfPeriod' => 8]];
        yield 'version below one' => [['version' => 0]];
        yield 'updated before created' => [['updatedAt' => '2026-02-28T10:00:00+00:00']];
    }

    public function testAConfirmedRecurrenceKeepsItsSubmittedScale(): void
    {
        $recurrence = self::recurrence();

        self::assertSame('-14.99', $recurrence->expectedAmount->value->toString());
        self::assertSame('0.30', $recurrence->amountTolerance->value->toString());
        self::assertSame(RecurrenceIntervalKind::MONTHLY, $recurrence->intervalKind);
        self::assertSame(4, $recurrence->dayOfPeriod);
        self::assertSame('2026-04-04', $recurrence->nextExpectedOn->format('Y-m-d'));
        self::assertNull($recurrence->archivedAt);
    }

    public function testAWeeklyRecurrenceAnchorsOnAnIsoWeekday(): void
    {
        self::assertSame(7, self::recurrence(['intervalKind' => RecurrenceIntervalKind::WEEKLY, 'dayOfPeriod' => 7])->dayOfPeriod);
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidRecurrences')]
    public function testAnUnsoundRecurrenceIsRefused(array $overrides): void
    {
        $this->expectException(InvalidRecurrence::class);

        self::recurrence($overrides);
    }

    /** @param array<string, mixed> $overrides */
    private static function recurrence(array $overrides = []): TransactionRecurrence
    {
        $values = array_merge([
            'id' => self::RECURRENCE,
            'accountId' => self::ACCOUNT,
            'label' => 'Netflix',
            'counterparty' => 'Netflix',
            'expectedAmount' => '-14.99',
            'amountTolerance' => '0.30',
            'asset' => 'EUR',
            'toleranceAsset' => 'EUR',
            'intervalKind' => RecurrenceIntervalKind::MONTHLY,
            'dayOfPeriod' => 4,
            'nextExpectedOn' => '2026-04-04',
            'version' => 1,
            'createdAt' => '2026-03-04T10:00:00+00:00',
            'updatedAt' => '2026-03-04T10:00:00+00:00',
        ], $overrides);

        \assert(is_string($values['id']) && is_string($values['accountId']) && is_string($values['label']));
        \assert(is_string($values['expectedAmount']) && is_string($values['amountTolerance']));
        \assert(is_string($values['asset']) && is_string($values['toleranceAsset']) && is_string($values['nextExpectedOn']));
        \assert(is_string($values['createdAt']) && is_string($values['updatedAt']));
        \assert($values['intervalKind'] instanceof RecurrenceIntervalKind);
        \assert(is_int($values['dayOfPeriod']) && is_int($values['version']));
        $counterparty = $values['counterparty'];
        \assert(null === $counterparty || is_string($counterparty));

        return new TransactionRecurrence(
            id: $values['id'],
            workspace: WorkspaceScope::fromString(self::WORKSPACE),
            accountId: $values['accountId'],
            label: $values['label'],
            counterparty: $counterparty,
            expectedAmount: new AssetAmount(DecimalValue::fromString($values['expectedAmount']), AssetCode::fromString($values['asset'])),
            amountTolerance: new AssetAmount(DecimalValue::fromString($values['amountTolerance']), AssetCode::fromString($values['toleranceAsset'])),
            intervalKind: $values['intervalKind'],
            dayOfPeriod: $values['dayOfPeriod'],
            nextExpectedOn: new \DateTimeImmutable($values['nextExpectedOn'], new \DateTimeZone('UTC')),
            confirmedAt: new \DateTimeImmutable($values['createdAt']),
            version: $values['version'],
            createdAt: new \DateTimeImmutable($values['createdAt']),
            updatedAt: new \DateTimeImmutable($values['updatedAt']),
            archivedAt: null,
        );
    }
}
