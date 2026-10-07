<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Application\CreateAccount;
use App\Module\Accounts\Application\CreateAccountInput;
use App\Module\Accounts\Application\ListAccountGroups;
use App\Module\Accounts\Application\ListAccounts;
use App\Module\Foundation\Application\DemoAccountBalanceAdjustment;
use App\Module\Foundation\Application\DemoAccountBalanceContributor;
use App\Module\Foundation\Domain\CalculatedDecimal;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;
use App\Module\Foundation\Domain\RoundingMode;
use App\Module\Portfolio\Application\LifeInsurance\AddFeeTerms;
use App\Module\Portfolio\Application\LifeInsurance\EuroFundRateInput;
use App\Module\Portfolio\Application\LifeInsurance\FeeTermsInput;
use App\Module\Portfolio\Application\LifeInsurance\LifeInsuranceContractInput;
use App\Module\Portfolio\Application\LifeInsurance\RecordEuroFundRate;
use App\Module\Portfolio\Application\LifeInsurance\SaveLifeInsuranceContract;
use App\Module\Transactions\Application\CreateTransfer;
use App\Module\Transactions\Application\CreateTransferInput;
use Symfony\Component\Clock\ClockInterface;

/** A contract with observed premiums, one surrender and complete monthly statements. */
final readonly class SeedDemoLifeInsurance implements DemoAccountBalanceContributor
{
    private const string OPENING_DAY = '2023-12-31';

    public function __construct(
        private CreateAccount $createAccount,
        private ListAccountGroups $listGroups,
        private CreateInstrument $createInstrument,
        private SaveLifeInsuranceContract $saveContract,
        private AddFeeTerms $addFeeTerms,
        private RecordEuroFundRate $recordRate,
        private ClockInterface $clock,
        private RecordPortfolioValuation $recordValuation,
        private RecordPortfolioOperation $recordOperation,
        private ListAccounts $listAccounts,
        private CreateTransfer $createTransfer,
        private \App\Module\Foundation\Application\CallerWorkspaceContext $caller,
        private ValuationHoldings $holdings,
    ) {
    }

    public function seed(): void
    {
        $groupId = null;
        foreach ($this->listGroups->__invoke(false, 1, ListAccountGroups::MAX_PAGE_SIZE)->items as $group) {
            if ('Investissements' === $group->label) {
                $groupId = $group->id;
                break;
            }
        }
        $account = ($this->createAccount)(new CreateAccountInput(
            label: 'Assurance-vie', assetCode: 'EUR', kind: 'INSURANCE_CONTRACT', productCode: 'FR_LIFE_INSURANCE',
            productModelId: null, institution: 'Assureur Démo', maskedIdentifier: null, valuationMode: 'PORTFOLIO',
            liquidityLevel: 'MEDIUM_TERM', includeInNetWorth: true, includeInEmergencyFund: false,
            openedOn: self::OPENING_DAY, closedOn: null, primaryGroupId: $groupId,
        ));
        ($this->saveContract)($account->id, new LifeInsuranceContractInput(
            insurer: 'Assureur Démo', distributor: null, commercialName: 'Contrat Démo Vie',
            contractNumberProvided: true, contractNumber: 'AV-00012345', subscribedOn: self::OPENING_DAY,
            taxStartOn: null, managementMode: 'FREE', version: null,
        ));
        $fund = ($this->createInstrument)(new CreateInstrumentInput(
            name: 'Fonds euros Démo', isin: null, ticker: null, venueMic: null, instrumentType: 'EURO_FUND',
            assetClass: null, region: null, currencyCode: 'EUR',
        ));
        $equity = ($this->createInstrument)(new CreateInstrumentInput(
            name: 'Fonds Actions Démo', isin: 'FRDEMO000064', ticker: null, venueMic: null, instrumentType: 'FUND',
            assetClass: 'EQUITY', region: null, currencyCode: 'EUR',
        ));
        ($this->addFeeTerms)($account->id, new FeeTermsInput(
            self::OPENING_DAY, '1', '0.60', '0.85', '0.50', null, 'Barème de démonstration',
        ));
        $today = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        ($this->recordOperation)($account->id, new OperationInput(
            type: 'PREMIUM', tradeDate: self::OPENING_DAY, grossAmount: self::amount('10000.00'), fees: self::amount('100.00'),
            legs: [new OperationLegInput('IN', $fund->id, null, null, self::amount('6000.00')),
                new OperationLegInput('IN', $equity->id, '40', '97.50', self::amount('3900.00'))],
        ));
        $source = $this->listAccounts->__invoke(false, false, 1, 100, 'SAVINGS')->items[0]->id;
        $opening = new \DateTimeImmutable(self::OPENING_DAY);
        ($this->recordValuation)($account->id, self::valuation($opening, $fund->id, $equity->id, '97.50'));
        $price = DecimalValue::fromString('97.50');
        for ($month = new \DateTimeImmutable('2024-01-01'); $month <= $today; $month = $month->modify('+1 month')) {
            if (0 !== self::quarterlyPremiumCents($month, $today->modify('-3 days'))) {
                $day = $month->modify('+14 days')->format('Y-m-d');
                $transfer = ($this->createTransfer)(new CreateTransferInput(
                    $source, $account->id, ['value' => '1500.00', 'assetCode' => 'EUR'], ['value' => '1500.00', 'assetCode' => 'EUR'],
                    'BOOKED', $day, null, 'Versement trimestriel assurance-vie', null, null,
                ));
                $quantity = CalculatedDecimal::fromDecimal(DecimalValue::fromString('885.00'))
                    ->proportion(CalculatedDecimal::one(), CalculatedDecimal::fromDecimal($price), 6, RoundingMode::DOWN);
                ($this->recordOperation)($account->id, new OperationInput(
                    type: 'PREMIUM', tradeDate: $day, grossAmount: self::amount('1500.00'), fees: self::amount('15.00'),
                    legs: [new OperationLegInput('IN', $fund->id, null, null, self::amount('600.00')),
                        new OperationLegInput('IN', $equity->id, $quantity->toString(), $price->toString(), self::amount('885.00'))],
                    linkedTransactionId: $transfer->target->id,
                ));
            }
            if ('2024-06' === $month->format('Y-m') && $today > $month->modify('+9 days')) {
                ($this->recordOperation)($account->id, new OperationInput(
                    type: 'PARTIAL_SURRENDER', tradeDate: '2024-06-10', grossAmount: self::amount('1050.00'),
                    legs: [new OperationLegInput('OUT', $equity->id, '10', '105.00', self::amount('1050.00'))],
                    withheldIncomeTax: self::amount('7.50'), withheldSocialContributions: self::amount('8.60'),
                ));
            }
            if ('2024-09' === $month->format('Y-m') && $today >= $month) {
                ($this->recordOperation)($account->id, new OperationInput(
                    type: 'SWITCH', tradeDate: '2024-09-01', fees: self::amount('10.00'),
                    legs: [new OperationLegInput('OUT', $fund->id, null, null, self::amount('2000.00')),
                        new OperationLegInput('IN', $equity->id, '20', '99.50', self::amount('1990.00'))],
                ));
            }
            $last = $month->modify('last day of this month');
            if ($last <= $today && '12' === $last->format('m')) {
                $rateYear = (int) $last->format('Y');
                $rate = $rateYear === (int) $today->format('Y') - 1 ? '2.80' : '2.50';
                ($this->recordRate)($account->id, new EuroFundRateInput($fund->id, $rateYear, $rate, null, 'NET_OF_MANAGEMENT_FEES', 'Taux de démonstration'));
                [$positions] = $this->holdings->held($this->caller->resolveContext()->workspace, $account->id, $last);
                foreach ($positions as $position) {
                    if ($position->instrumentId !== $fund->id) {
                        continue;
                    }
                    $gross = CalculatedDecimal::fromDecimal($position->quantity)->proportion(CalculatedDecimal::fromDecimal(DecimalValue::fromString($rate)), CalculatedDecimal::fromDecimal(DecimalValue::fromString('100')), 2, RoundingMode::HALF_EVEN);
                    $taxes = $gross->proportion(CalculatedDecimal::fromDecimal(DecimalValue::fromString('0.172')), CalculatedDecimal::one(), 2, RoundingMode::HALF_EVEN);
                    ($this->recordOperation)($account->id, new OperationInput(type: 'EURO_FUND_INTEREST', tradeDate: $last->format('Y-m-d'), instrumentId: $fund->id,
                        grossAmount: self::amount($gross->toString()), taxes: self::amount($taxes->toString()),
                        interestBasis: 2024 === $rateYear ? 'UNKNOWN' : 'NET_OF_MANAGEMENT_FEES', rateYear: $rateYear,
                        note: 'Montant de démonstration, prélèvements sociaux observés de 17,2 %'));
                }
                $fee = CalculatedDecimal::fromDecimal(ExactDecimal::multiply(DecimalValue::fromString('0.30'), $price))->round(2, RoundingMode::HALF_EVEN);
                ($this->recordOperation)($account->id, new OperationInput(type: 'MANAGEMENT_FEE', tradeDate: $last->format('Y-m-d'), instrumentId: $equity->id,
                    fees: self::amount($fee->toString()), quantity: '0.30', unitPrice: $price->toString()));
            }
            if ($last < $today) {
                ($this->recordValuation)($account->id, self::valuation($last, $fund->id, $equity->id, $price->toString()));
            }
            $price = ExactDecimal::add($price, DecimalValue::fromString('0.25'));
        }
    }

    public function balanceAdjustments(): array
    {
        $source = $this->listAccounts->__invoke(false, false, 1, 100, 'SAVINGS')->items[0]->id;
        $cutoff = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0)->modify('-3 days');
        $adjustments = [];
        for ($month = new \DateTimeImmutable('2024-01-01'); $month <= $cutoff; $month = $month->modify('+1 month')) {
            $cents = self::quarterlyPremiumCents($month, $cutoff);
            if (0 !== $cents) {
                $adjustments[] = new DemoAccountBalanceAdjustment($source, $month->modify('+14 days'), -$cents, 1);
            }
        }

        return $adjustments;
    }

    private static function quarterlyPremiumCents(\DateTimeImmutable $month, \DateTimeImmutable $cutoff): int
    {
        return $month >= new \DateTimeImmutable('2024-01-01') && in_array((int) $month->format('n'), [1, 4, 7, 10], true)
            && $month->modify('+14 days') <= $cutoff ? 150000 : 0;
    }

    private static function amount(string $value): AmountInput
    {
        return new AmountInput($value, 'EUR');
    }

    private static function valuation(\DateTimeImmutable $day, string $fund, string $equity, string $price): ValuationInput
    {
        $date = $day->format('Y-m-d');

        return new ValuationInput($date, 'POSITIONS', null, [new PriceInput($fund, '1', $date), new PriceInput($equity, $price, $date)], null, null, null, null);
    }
}
