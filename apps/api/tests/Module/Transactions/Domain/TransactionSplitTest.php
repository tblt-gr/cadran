<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Domain;

use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Transactions\Domain\InvalidTransaction;
use App\Module\Transactions\Domain\TransactionSplit;
use App\Tests\Support\WorkspaceFixture;
use PHPUnit\Framework\TestCase;

final class TransactionSplitTest extends TestCase
{
    private const string SPLIT_ID = '00000000-0000-7000-8000-0000000000e1';
    private const string TRANSACTION_ID = '00000000-0000-7000-8000-0000000000f1';
    private const string CATEGORY_ID = '00000000-0000-7000-8000-0000000000c1';

    public function testAZeroAmountIsRefused(): void
    {
        $this->expectException(InvalidTransaction::class);
        $this->split('0.00');
    }

    public function testARepeatedAnalyticAxisIsRefused(): void
    {
        $this->expectException(InvalidTransaction::class);
        $this->split('-10.00', [AnalyticAxis::ESSENTIAL, AnalyticAxis::ESSENTIAL]);
    }

    public function testANoteBeyondItsBoundIsRefused(): void
    {
        $this->expectException(InvalidTransaction::class);
        $this->split('-10.00', [], str_repeat('a', 141));
    }

    public function testAWellFormedSplitIsAccepted(): void
    {
        $split = $this->split('-10.00', [AnalyticAxis::ESSENTIAL], 'Courses');

        self::assertSame('-10.00', $split->amount->value->toString());
        self::assertSame([AnalyticAxis::ESSENTIAL], $split->analyticAxes);
        self::assertSame('Courses', $split->note);
    }

    /** @param list<AnalyticAxis> $axes */
    private function split(string $amount, array $axes = [], ?string $note = null): TransactionSplit
    {
        return new TransactionSplit(
            id: self::SPLIT_ID,
            workspace: WorkspaceFixture::own(),
            transactionId: self::TRANSACTION_ID,
            categoryId: self::CATEGORY_ID,
            amount: new AssetAmount(DecimalValue::fromString($amount), AssetCode::fromString('EUR')),
            analyticAxes: $axes,
            note: $note,
            createdAt: new \DateTimeImmutable('2026-03-14T09:12:04+00:00'),
        );
    }
}
