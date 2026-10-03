<?php

declare(strict_types=1);

namespace App\Module\Demo\Application;

use App\Module\Portfolio\Application\AmountInput;
use App\Module\Portfolio\Application\CorrectPortfolioOperation;
use App\Module\Portfolio\Application\ListInstruments;
use App\Module\Portfolio\Application\OperationInput;
use App\Module\Portfolio\Application\RecordPortfolioOperation;
use App\Module\Portfolio\Application\VoidPortfolioOperation;

/**
 * The demo life of the `Compte-titres`: what a broker statement would show.
 *
 * Prices are integers in ten-thousandths and quantities integers in
 * millionths, so the gross amount of every buy is rounded to the cent with
 * integer arithmetic and no float. A second generator seeded like the ledger
 * one draws the price path, which leaves the ledger itself untouched.
 */
final class SeedDemoPortfolio
{
    public const int UNLINKED_TRANSFER_MONTH = 3;
    public const int WITHDRAWAL_MONTH = 4;
    public const int WITHDRAWAL_CENTS = 10_000;
    private const string ASSET = 'EUR';
    private const string ETF = 'ETF Monde Démo';
    private const string STOCK = 'Action Démo Industrie';
    private const string UNCLASSIFIED = 'Instrument sans classe Démo';
    private const int PRICE_SCALE = 4;
    private const int QUANTITY_SCALE = 6;

    private DemoRandom $random;
    /** ETF price in ten-thousandths of a euro. */
    private int $price = 1_502_500;
    /** @var array<string, string> */
    private array $instruments = [];

    public function __construct(
        private readonly RecordPortfolioOperation $record,
        private readonly CorrectPortfolioOperation $correct,
        private readonly VoidPortfolioOperation $void,
        private readonly ListInstruments $listInstruments,
    ) {
        $this->random = new DemoRandom(0);
    }

    /** The opening deposit mirrors the opening balance, then three buys leave a small cash remainder. */
    public function open(string $accountId, string $day, int $openingCents, int $seed): void
    {
        $this->random = new DemoRandom($seed);
        $this->price = 1_502_500;
        $this->deposit($accountId, $day, $openingCents, null);
        $this->buy($accountId, $day, self::ETF, 10_000_000, 1_502_500, 'Achat initial');
        $this->buy($accountId, $day, self::STOCK, 20_000_000, 524_000, 'Achat initial');
        $this->buy($accountId, $day, self::UNCLASSIFIED, 100_000_000, 240_000, 'Achat initial');
    }

    /** The monthly transfer is mirrored by a linked deposit and invested in the world ETF the same day. */
    public function investMonthly(string $accountId, string $legId, string $day, int $cents, int $monthIndex): void
    {
        $this->price = max(1, $this->price + intdiv($this->price * $this->random->between(-20, 30), 1000));
        $this->deposit($accountId, $day, $cents, $legId);
        $quantity = intdiv($cents * 100_000_000, $this->price);
        $this->buy($accountId, $day, self::ETF, $quantity, $this->price, 'Investissement mensuel');

        $month = substr($day, 0, 8);
        match ($monthIndex) {
            2 => $this->incomeAndCosts($accountId, $month),
            5 => $this->modifiedBuy($accountId, $month.'10'),
            8 => $this->split($accountId, $month.'12'),
            self::WITHDRAWAL_MONTH => $this->withdrawal($accountId, $month.'18'),
            default => null,
        };
    }

    private function incomeAndCosts(string $accountId, string $month): void
    {
        ($this->record)($accountId, new OperationInput(
            type: 'DIVIDEND', tradeDate: $month.'15', instrumentId: $this->instrument(self::STOCK),
            grossAmount: self::amount('12.50'), taxes: self::amount('3.75'), reference: 'Dividende annuel',
        ));
        ($this->record)($accountId, new OperationInput(type: 'FEE', tradeDate: $month.'20', fees: self::amount('4.80'), reference: 'Droits de garde'));
        $duplicate = ($this->record)($accountId, new OperationInput(type: 'FEE', tradeDate: $month.'20', fees: self::amount('4.80'), reference: 'Droits de garde'));
        ($this->void)($duplicate->id, $duplicate->version, 'Doublon de saisie');
        $grossCents = intdiv(2 * $this->price + 50, 100);
        ($this->record)($accountId, new OperationInput(
            type: 'SELL', tradeDate: $month.'22', instrumentId: $this->instrument(self::ETF),
            quantity: self::fixed(2_000_000, self::QUANTITY_SCALE), unitPrice: self::fixed($this->price, self::PRICE_SCALE),
            grossAmount: self::amount(DemoCents::format($grossCents)), fees: self::amount('1.99'), reference: 'Vente partielle',
        ));
    }

    private function modifiedBuy(string $accountId, string $day): void
    {
        $mistyped = $this->buy($accountId, $day, self::STOCK, 5_000_000, 532_000, 'Saisie à corriger');
        // 5 x 53.2000 was entered where the statement says 6 units.
        ($this->correct)($mistyped, 1, 'Quantité corrigée', new OperationInput(
            type: 'BUY', tradeDate: $day, instrumentId: $this->instrument(self::STOCK),
            quantity: self::fixed(6_000_000, self::QUANTITY_SCALE), unitPrice: self::fixed(532_000, self::PRICE_SCALE),
            grossAmount: self::amount('319.20'), reference: 'Saisie à corriger',
        ));
    }

    /** Cash taken out of the portfolio and left unlinked: the history before a transfer was tracked is legitimate. */
    private function withdrawal(string $accountId, string $day): void
    {
        ($this->record)($accountId, new OperationInput(
            type: 'WITHDRAWAL', tradeDate: $day, grossAmount: self::amount(DemoCents::format(self::WITHDRAWAL_CENTS)), reference: 'Retrait',
        ));
    }

    private function split(string $accountId, string $day): void
    {
        ($this->record)($accountId, new OperationInput(
            type: 'SPLIT', tradeDate: $day, instrumentId: $this->instrument(self::STOCK), ratioNumerator: '2', ratioDenominator: '1',
            reference: 'Division par deux',
        ));
    }

    private function deposit(string $accountId, string $day, int $cents, ?string $legId): void
    {
        ($this->record)($accountId, new OperationInput(
            type: 'DEPOSIT', tradeDate: $day, grossAmount: self::amount(DemoCents::format($cents)), linkedTransactionId: $legId,
        ));
    }

    /** @return string the identifier of the recorded operation */
    private function buy(string $accountId, string $day, string $instrument, int $quantity, int $price, string $reference): string
    {
        // quantity (millionths) x price (ten-thousandths) is in 1e-10 euro: half a cent is 5e7.
        $grossCents = intdiv($quantity * $price + 50_000_000, 100_000_000);

        return ($this->record)($accountId, new OperationInput(
            type: 'BUY', tradeDate: $day, instrumentId: $this->instrument($instrument),
            quantity: self::fixed($quantity, self::QUANTITY_SCALE), unitPrice: self::fixed($price, self::PRICE_SCALE),
            grossAmount: self::amount(DemoCents::format($grossCents)), reference: $reference,
        ))->id;
    }

    private function instrument(string $name): string
    {
        if ([] === $this->instruments) {
            foreach ($this->listInstruments->__invoke('Démo', 'all', 1, ListInstruments::MAX_PAGE_SIZE)->items as $item) {
                $this->instruments[$item->name] = $item->id;
            }
        }

        return $this->instruments[$name] ?? throw new \LogicException(sprintf('The demo instrument "%s" is missing.', $name));
    }

    private static function amount(string $value): AmountInput
    {
        return new AmountInput($value, self::ASSET);
    }

    private static function fixed(int $units, int $scale): string
    {
        $digits = str_pad((string) $units, $scale + 1, '0', STR_PAD_LEFT);

        return substr($digits, 0, -$scale).'.'.substr($digits, -$scale);
    }
}
