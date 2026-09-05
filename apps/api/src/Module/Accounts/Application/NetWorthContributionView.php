<?php

declare(strict_types=1);

namespace App\Module\Accounts\Application;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\NetWorthContribution;
use App\Module\Accounts\Domain\NetWorthShare;
use App\Module\Reference\Domain\Asset;

/**
 * One contributing source of the published net worth, with everything needed
 * to challenge it: the account, its signed effect, and how fresh the figure
 * behind it is.
 */
final readonly class NetWorthContributionView
{
    public function __construct(
        public string $accountId,
        public string $label,
        public string $kind,
        public int $netWorthSign,
        public ?string $primaryGroupId,
        public ?string $primaryGroupLabel,
        public bool $eligible,
        public ?NetWorthAmountView $amount,
        public ?NetWorthAmountView $signedAmount,
        public string $quality,
        public ?int $ageDays,
        public ?string $valuedOn,
        public ShareView $share,
    ) {
    }

    public static function of(
        Account $account,
        NetWorthContribution $contribution,
        ?string $primaryGroupLabel,
        NetWorthShare $share,
        ?Asset $reference,
    ): self {
        return new self(
            accountId: $account->id,
            label: $account->label,
            kind: $account->kind->value,
            netWorthSign: $contribution->netWorthSign,
            primaryGroupId: $contribution->primaryGroupId,
            primaryGroupLabel: $primaryGroupLabel,
            eligible: $contribution->eligible,
            amount: NetWorthAmountView::of($contribution->amount?->value, $contribution->amount?->asset, $reference),
            signedAmount: NetWorthAmountView::of($contribution->signedValue(), $contribution->amount?->asset, $reference),
            quality: $contribution->quality->value,
            ageDays: $contribution->ageDays,
            valuedOn: $contribution->valuedOn?->format('Y-m-d'),
            share: ShareView::fromShare($share),
        );
    }
}
