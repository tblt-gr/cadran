<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Application\CreateAccount;
use App\Module\Accounts\Application\CreateAccountInput;
use App\Module\Accounts\Application\ListAccountGroups;
use App\Module\Foundation\Application\DemoDataContributor;
use App\Module\Portfolio\Application\Takeover\TakeoverInput;
use App\Module\Portfolio\Application\Takeover\TakeoverLineInput;
use App\Module\Portfolio\Application\Takeover\TakeOverPortfolioPositions;
use Symfony\Component\Clock\ClockInterface;

/**
 * A securities account opened through a takeover: the positions already held are entered once at a month end, then
 * one total valuation per closed month follows, growing by integer cents.
 */
final readonly class SeedDemoTakeover implements DemoDataContributor
{
    public const string LABEL = 'PEA';
    private const int MONTHLY_GROWTH_PER_MILLE = 4;
    /** 12 x 98.24 + 30 x 41.10 + 250.00 cash, in cents. */
    private const int TAKEOVER_CENTS = 12 * 9824 + 30 * 4110 + 25000;

    public function __construct(
        private CreateAccount $createAccount,
        private ListAccountGroups $listGroups,
        private ListInstruments $listInstruments,
        private TakeOverPortfolioPositions $takeOver,
        private RecordPortfolioValuation $recordValuation,
        private ClockInterface $clock,
    ) {
    }

    public function seed(): void
    {
        $today = $this->clock->now()->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);
        $takenOverOn = $today->modify('first day of this month')->modify('-2 months')->modify('last day of this month');
        $day = $takenOverOn->format('Y-m-d');
        $groupId = null;
        foreach ($this->listGroups->__invoke(false, 1, ListAccountGroups::MAX_PAGE_SIZE)->items as $group) {
            if ('Investissements' === $group->label) {
                $groupId = $group->id;
                break;
            }
        }
        $account = ($this->createAccount)(new CreateAccountInput(
            label: self::LABEL, assetCode: 'EUR', kind: 'PORTFOLIO', productCode: 'FR_PEA',
            productModelId: null, institution: 'Courtier Démo', maskedIdentifier: null, valuationMode: 'PORTFOLIO',
            liquidityLevel: 'MEDIUM_TERM', includeInNetWorth: true, includeInEmergencyFund: false,
            openedOn: $day, closedOn: null, primaryGroupId: $groupId,
        ));
        $etf = $this->instrument('ETF Monde Démo');
        $bond = $this->instrument('Fonds Obligataire Démo');
        ($this->takeOver)($account->id, new TakeoverInput($day, [
            new TakeoverLineInput($etf, '12', '1105.20', '98.24', null),
            new TakeoverLineInput($bond, '30', null, '41.10', null),
        ], '250.00'));

        $total = self::TAKEOVER_CENTS;
        for ($month = $takenOverOn->modify('first day of next month'); $month->modify('last day of this month') < $today; $month = $month->modify('first day of next month')) {
            $total += intdiv($total * self::MONTHLY_GROWTH_PER_MILLE, 1000);
            $last = $month->modify('last day of this month')->format('Y-m-d');
            ($this->recordValuation)($account->id, new ValuationInput(
                $last, 'TOTAL', new AmountInput(self::format($total), 'EUR'), null, null, null, null, null,
            ));
        }
    }

    private function instrument(string $name): string
    {
        foreach ($this->listInstruments->__invoke('Démo', 'all', 1, ListInstruments::MAX_PAGE_SIZE)->items as $item) {
            if ($item->name === $name) {
                return $item->id;
            }
        }

        throw new \LogicException(sprintf('The demo instrument "%s" is missing.', $name));
    }

    private static function format(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), $cents % 100);
    }
}
