<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Takeover;

use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountValuationMode;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use App\Module\Portfolio\Domain\Takeover\PortfolioTakeoverBlockReason;
use App\Module\Portfolio\Domain\Takeover\PortfolioTakeoverRepository;
use App\Module\Portfolio\Domain\Valuation\ValuationRepository;

/** The rule of the first takeover: an empty, supported securities account, with no cash movement up to the day. */
final readonly class PortfolioTakeoverEligibility
{
    public function __construct(
        private PortfolioTakeoverRepository $takeovers,
        private ValuationRepository $valuations,
        private PortfolioOperationRepository $operations,
    ) {
    }

    /** @param \DateTimeImmutable $cashCutoff the day on or before which no cash operation may exist */
    public function blockingReason(Account $account, \DateTimeImmutable $cashCutoff): ?PortfolioTakeoverBlockReason
    {
        if (AccountKind::PORTFOLIO !== $account->kind || AccountValuationMode::PORTFOLIO !== $account->valuationMode || null !== $account->archivedAt || $account->isClosed()) {
            return PortfolioTakeoverBlockReason::NOT_SUPPORTED;
        }

        return match (true) {
            null !== $this->takeovers->findByAccount($account->workspace, $account->id) => PortfolioTakeoverBlockReason::ALREADY_TAKEN_OVER,
            $this->valuations->countActive($account->workspace, $account->id) > 0 => PortfolioTakeoverBlockReason::ACTIVE_VALUATION,
            $this->operations->hasActiveSecurityMovement($account->workspace, $account->id) => PortfolioTakeoverBlockReason::SECURITY_OPERATION,
            $this->operations->hasActiveCashOperationOnOrBefore($account->workspace, $account->id, $cashCutoff) => PortfolioTakeoverBlockReason::CASH_OPERATION_BEFORE,
            default => null,
        };
    }
}
