<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\AccountShareInput;
use App\Module\Accounts\Domain\GroupLineage;
use App\Module\Accounts\Domain\NetWorth;
use App\Module\Accounts\Domain\NetWorthCalculator;
use App\Module\Accounts\Domain\NetWorthContribution;
use App\Module\Accounts\Domain\NetWorthShareCalculator;
use App\Module\Accounts\Domain\NetWorthShares;

/** The aggregate and the share figures of one day, from contributions already read. */
final readonly class NetWorthDayFigures
{
    /**
     * @param list<AccountShareInput> $shareInputs
     * @param list<GroupLineage>      $lineages
     */
    private function __construct(
        public NetWorth $netWorth,
        public array $shareInputs,
        private array $lineages,
    ) {
    }

    /**
     * @param list<NetWorthContribution> $contributions
     * @param list<GroupLineage>         $lineages
     */
    public static function of(array $contributions, \DateTimeImmutable $day, array $lineages): self
    {
        return new self(
            NetWorthCalculator::compute($contributions, $day),
            array_map(static fn (NetWorthContribution $contribution): AccountShareInput => $contribution->share(), $contributions),
            $lineages,
        );
    }

    public function shares(): NetWorthShares
    {
        return NetWorthShareCalculator::compute($this->shareInputs, $this->lineages);
    }
}
