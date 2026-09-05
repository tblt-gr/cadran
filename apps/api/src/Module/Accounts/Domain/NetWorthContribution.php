<?php

declare(strict_types=1);

namespace App\Module\Accounts\Domain;

use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\ExactDecimal;

/**
 * What one account brings to the net worth of one business day.
 *
 * The amount is the stored figure, always positive: a loan records the capital
 * still owed as it appears on the statement. The sign lives in the account
 * kind, so {@see signedValue()} is the only place the two meet.
 */
final readonly class NetWorthContribution
{
    /**
     * @param list<string> $tagGroupIds
     * @param bool         $eligible    included in net worth, open on the requested
     *                                  day and not archived; an account outside that
     *                                  window is not a missing valuation
     */
    public function __construct(
        public string $accountId,
        public int $netWorthSign,
        public ?string $primaryGroupId,
        public array $tagGroupIds,
        public ?AssetAmount $amount,
        public ValuationQuality $quality,
        public ?int $ageDays,
        public ?\DateTimeImmutable $valuedOn,
        public bool $eligible,
    ) {
    }

    public function signedValue(): ?DecimalValue
    {
        return null === $this->amount ? null : ExactDecimal::signed($this->amount->value, $this->netWorthSign);
    }

    /**
     * The same facts, reduced to what the exclusive share calculator reads. An
     * ineligible account is passed through as excluded so it never lands in a
     * denominator.
     */
    public function share(): AccountShareInput
    {
        return new AccountShareInput(
            accountId: $this->accountId,
            includeInNetWorth: $this->eligible,
            netWorthSign: $this->netWorthSign,
            primaryGroupId: $this->primaryGroupId,
            tagGroupIds: $this->tagGroupIds,
            value: $this->amount?->value,
            asset: $this->amount?->asset,
        );
    }
}
