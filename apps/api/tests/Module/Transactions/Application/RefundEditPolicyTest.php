<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

use App\Module\Foundation\Application\AmountInputParser;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\RefundEditPolicy;
use App\Module\Transactions\Application\TransactionDraft;
use App\Module\Transactions\Application\TransactionSplitInputParser;
use App\Module\Transactions\Application\UpdateTransactionInput;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionNature;
use App\Module\Transactions\Domain\TransactionSource;
use App\Module\Transactions\Domain\TransactionState;
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

final class RefundEditPolicyTest extends TestCase
{
    public function testTheRecordedAmountLiteralPasses(): void
    {
        $this->expectNotToPerformAssertions();

        $this->assertUnchanged('12.50', '12.50');
    }

    /** The recorded literal is part of the accepted allocation, so an equal value written differently is a change. */
    public function testAnEqualAmountWrittenWithAnotherScaleIsRefused(): void
    {
        $this->expectException(InvalidTransactionInput::class);
        $this->expectExceptionMessage('A refund amount cannot change');

        $this->assertUnchanged('12.50', '12.5');
    }

    private function assertUnchanged(string $stored, string $submitted): void
    {
        $now = new \DateTimeImmutable('2026-03-14T09:12:04+00:00');
        $day = new \DateTimeImmutable('2026-03-14');
        $current = new Transaction(
            '00000000-0000-7000-8000-0000000000f1', WorkspaceFixture::own(), '00000000-0000-7000-8000-0000000000d1',
            self::amount($stored), null, null, TransactionState::BOOKED, TransactionNature::REFUND, TransactionSource::MANUAL,
            null, $day, null, null, 'Refund', null, null, null, null, null, null, [], 1, $now, $now, null, null,
        );
        $draft = new TransactionDraft(self::amount($submitted), TransactionNature::REFUND, TransactionState::BOOKED, $day, null, null, 'Refund', null, null, null, null, null, null);
        $input = new UpdateTransactionInput(
            '00000000-0000-7000-8000-0000000000d1', ['value' => $submitted, 'assetCode' => 'EUR'], 'REFUND', 'BOOKED', '2026-03-14',
            null, null, 'Refund', null, null, null, null, null, null, null, null, 1,
        );

        RefundEditPolicy::assertUnchanged($current, $draft, $input, new TransactionSplitInputParser(new AmountInputParser(InMemoryAssetCatalog::withCodes('EUR'))));
    }

    private static function amount(string $value): AssetAmount
    {
        return new AssetAmount(DecimalValue::fromString($value), AssetCode::fromString('EUR'));
    }
}
