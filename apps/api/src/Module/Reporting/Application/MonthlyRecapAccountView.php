<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\NetWorthContributionView;
use App\Module\Accounts\Application\ShareView;
use App\Module\Accounts\Domain\NetWorthContribution;

/**
 * One account of the recap: what it was worth at N−1, what it is worth at N,
 * and the signed weight it carries in the eligible net worth of N.
 *
 * A value is absent, never zero, when the day holds no valuation; the whole
 * value object is absent when the workspace did not hold the account on that
 * day at all, which is a different statement from "worth nothing".
 */
final readonly class MonthlyRecapAccountView
{
    public function __construct(
        public string $accountId,
        public string $label,
        public string $kind,
        public int $netWorthSign,
        public ?string $primaryGroupId,
        public ?string $primaryGroupLabel,
        public bool $eligible,
        public ?MonthlyAccountValueView $previousValue,
        public ?MonthlyAccountValueView $currentValue,
        public ShareView $share,
    ) {
    }

    public static function of(NetWorthContributionView $current, ?NetWorthContribution $previous): self
    {
        return new self(
            accountId: $current->accountId,
            label: $current->label,
            kind: $current->kind,
            netWorthSign: $current->netWorthSign,
            primaryGroupId: $current->primaryGroupId,
            primaryGroupLabel: $current->primaryGroupLabel,
            eligible: $current->eligible,
            previousValue: null === $previous || !$previous->eligible ? null : new MonthlyAccountValueView(
                $previous->amount?->value->toString(),
                $previous->amount?->asset->toString(),
                $previous->quality->value,
                $previous->ageDays,
                $previous->valuedOn?->format('Y-m-d'),
            ),
            currentValue: $current->eligible ? new MonthlyAccountValueView(
                $current->amount?->amount,
                $current->amount?->asset,
                $current->quality,
                $current->ageDays,
                $current->valuedOn,
            ) : null,
            share: $current->share,
        );
    }
}
