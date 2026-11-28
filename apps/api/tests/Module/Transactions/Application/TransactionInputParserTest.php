<?php

declare(strict_types=1);

namespace App\Tests\Module\Transactions\Application;

use App\Module\Foundation\Application\AmountInputParser;
use App\Module\Foundation\Application\InvalidAmountInput;
use App\Module\Transactions\Application\InvalidTransactionInput;
use App\Module\Transactions\Application\TransactionDraft;
use App\Module\Transactions\Application\TransactionInputParser;
use App\Tests\Module\Reference\Application\Double\InMemoryAssetCatalog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TransactionInputParserTest extends TestCase
{
    public function testItReadsCalendarDaysAndKeepsTheSubmittedAmountLiteral(): void
    {
        $draft = $this->parse(bookedOn: '2026-02-28', valueOn: '2026-03-01', authorizedOn: '2026-02-27');

        self::assertSame('2026-02-28', $draft->bookedOn->format('Y-m-d'));
        self::assertSame('2026-03-01', $draft->valueOn?->format('Y-m-d'));
        self::assertSame('2026-02-27', $draft->authorizedOn?->format('Y-m-d'));
        self::assertSame('-12.50', $draft->amount->value->toString());
    }

    /** @return iterable<string, array{string, ?string}> */
    public static function impossibleDays(): iterable
    {
        yield 'a day the month does not have' => ['2026-02-29', null];
        yield 'not an ISO day' => ['28/02/2026', null];
        yield 'an empty value date' => ['2026-02-28', ''];
    }

    #[DataProvider('impossibleDays')]
    public function testItRefusesAnImpossibleDay(string $bookedOn, ?string $valueOn): void
    {
        $this->expectException(InvalidTransactionInput::class);

        $this->parse(bookedOn: $bookedOn, valueOn: $valueOn);
    }

    public function testItAcceptsTheStorageScaleOfTheAssetAndRefusesOneDigitMore(): void
    {
        self::assertSame('-0.00000001', $this->parse(amount: '-0.00000001')->amount->value->toString());

        $this->expectException(InvalidAmountInput::class);

        $this->parse(amount: '-0.000000001');
    }

    /** The label rule belongs to the entity: the parser hands the literal over untouched. */
    public function testItLeavesAnEmptyLabelForTheEntityToRefuse(): void
    {
        self::assertSame('', $this->parse(label: '')->rawLabel);
    }

    private function parse(
        string $amount = '-12.50',
        string $bookedOn = '2026-02-28',
        ?string $valueOn = null,
        ?string $authorizedOn = null,
        string $label = 'Courses',
    ): TransactionDraft {
        return (new TransactionInputParser(new AmountInputParser(InMemoryAssetCatalog::withCodes('EUR'))))->parse(
            ['value' => $amount, 'assetCode' => 'EUR'], 'EXPENSE', 'BOOKED', $bookedOn, $valueOn, $authorizedOn,
            $label, null, null, null, null, null, null,
        );
    }
}
