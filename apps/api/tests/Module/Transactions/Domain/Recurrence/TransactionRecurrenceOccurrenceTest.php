<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain\Recurrence;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Recurrence\InvalidRecurrence;
use App\Module\Transactions\Domain\Recurrence\OccurrenceStatus;
use App\Module\Transactions\Domain\Recurrence\TransactionRecurrenceOccurrence;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransactionRecurrenceOccurrenceTest extends TestCase
{
    private const string WORKSPACE = '00000000-0000-7000-8000-0000000000a1';
    private const string OCCURRENCE = '00000000-0000-7000-8000-0000000000f1';
    private const string RECURRENCE = '00000000-0000-7000-8000-0000000000e1';
    private const string TRANSACTION = '00000000-0000-7000-8000-0000000000b9';

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function invalidOccurrences(): iterable
    {
        yield 'malformed identifier' => [['id' => 'not-a-uuid']];
        yield 'malformed recurrence' => [['recurrenceId' => 'not-a-uuid']];
        yield 'malformed matched transaction' => [['status' => OccurrenceStatus::RECEIVED, 'matchedTransactionId' => 'not-a-uuid', 'matchedAt' => '2026-04-04T10:00:00+00:00']];
        yield 'zero expected amount' => [['expectedAmount' => '0.00']];
        yield 'negative tolerance' => [['amountTolerance' => '-0.01']];
        yield 'tolerance in another asset' => [['toleranceAsset' => 'USD']];
        yield 'received without a transaction' => [['status' => OccurrenceStatus::RECEIVED]];
        yield 'received without a match instant' => [['status' => OccurrenceStatus::RECEIVED, 'matchedTransactionId' => self::TRANSACTION]];
        yield 'expected but matched' => [['matchedTransactionId' => self::TRANSACTION, 'matchedAt' => '2026-04-04T10:00:00+00:00']];
        yield 'match instant without a transaction' => [['matchedAt' => '2026-04-04T10:00:00+00:00']];
    }

    public function testAnExpectedOccurrenceSnapshotsItsAmountAndTolerance(): void
    {
        $occurrence = self::occurrence();

        self::assertSame(OccurrenceStatus::EXPECTED, $occurrence->status);
        self::assertSame('-14.99', $occurrence->expectedAmount->value->toString());
        self::assertSame('0.30', $occurrence->amountTolerance->value->toString());
        self::assertNull($occurrence->matchedTransactionId);
        self::assertNull($occurrence->matchedAt);
    }

    public function testAReceivedOccurrenceNamesTheTransactionItMatched(): void
    {
        $occurrence = self::occurrence([
            'status' => OccurrenceStatus::RECEIVED,
            'matchedTransactionId' => self::TRANSACTION,
            'matchedAt' => '2026-04-04T10:00:00+00:00',
        ]);

        self::assertSame(self::TRANSACTION, $occurrence->matchedTransactionId);
        self::assertNotNull($occurrence->matchedAt);
    }

    /** @param array<string, mixed> $overrides */
    #[DataProvider('invalidOccurrences')]
    public function testAnUnsoundOccurrenceIsRefused(array $overrides): void
    {
        $this->expectException(InvalidRecurrence::class);

        self::occurrence($overrides);
    }

    /** @param array<string, mixed> $overrides */
    private static function occurrence(array $overrides = []): TransactionRecurrenceOccurrence
    {
        $values = array_merge([
            'id' => self::OCCURRENCE,
            'recurrenceId' => self::RECURRENCE,
            'expectedOn' => '2026-04-04',
            'expectedAmount' => '-14.99',
            'amountTolerance' => '0.30',
            'asset' => 'EUR',
            'toleranceAsset' => 'EUR',
            'matchedTransactionId' => null,
            'matchedAt' => null,
            'status' => OccurrenceStatus::EXPECTED,
        ], $overrides);

        \assert(is_string($values['id']) && is_string($values['recurrenceId']) && is_string($values['expectedOn']));
        \assert(is_string($values['expectedAmount']) && is_string($values['amountTolerance']));
        \assert(is_string($values['asset']) && is_string($values['toleranceAsset']));
        \assert($values['status'] instanceof OccurrenceStatus);
        $matchedTransactionId = $values['matchedTransactionId'];
        $matchedAt = $values['matchedAt'];
        \assert(null === $matchedTransactionId || is_string($matchedTransactionId));
        \assert(null === $matchedAt || is_string($matchedAt));

        return new TransactionRecurrenceOccurrence(
            id: $values['id'],
            workspace: WorkspaceScope::fromString(self::WORKSPACE),
            recurrenceId: $values['recurrenceId'],
            expectedOn: new \DateTimeImmutable($values['expectedOn'], new \DateTimeZone('UTC')),
            expectedAmount: new AssetAmount(DecimalValue::fromString($values['expectedAmount']), AssetCode::fromString($values['asset'])),
            amountTolerance: new AssetAmount(DecimalValue::fromString($values['amountTolerance']), AssetCode::fromString($values['toleranceAsset'])),
            matchedTransactionId: $matchedTransactionId,
            matchedAt: null === $matchedAt ? null : new \DateTimeImmutable($matchedAt),
            status: $values['status'],
        );
    }
}
