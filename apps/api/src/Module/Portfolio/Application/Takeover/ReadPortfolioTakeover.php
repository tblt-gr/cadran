<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\Takeover;

use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Portfolio\Application\PortfolioAccountNotFound;
use App\Module\Portfolio\Domain\OperationType;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use App\Module\Portfolio\Domain\Takeover\PortfolioTakeover;
use App\Module\Portfolio\Domain\Takeover\PortfolioTakeoverRepository;

/** Whether an account can take over its positions and, once done, the takeover it carries. */
final readonly class ReadPortfolioTakeover
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private AccountRepository $accounts,
        private PortfolioTakeoverRepository $takeovers,
        private PortfolioOperationRepository $operations,
        private PortfolioTakeoverEligibility $eligibility,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(string $accountId): PortfolioTakeoverState
    {
        $workspace = $this->caller->resolveContext()->workspace;
        $account = $this->accounts->find($workspace, $accountId) ?? throw new PortfolioAccountNotFound();
        $lastOfPreviousMonth = $this->calendar->today()->modify('first day of this month')->modify('-1 day');
        $defaultDate = max($account->openedOn, $lastOfPreviousMonth);
        $reason = $this->eligibility->blockingReason($account, $defaultDate);
        $takeover = $this->takeovers->findByAccount($workspace, $account->id);

        return new PortfolioTakeoverState(null === $reason, $reason, $defaultDate, null === $takeover ? null : $this->view($workspace, $takeover));
    }

    private function view(WorkspaceScope $workspace, PortfolioTakeover $takeover): PortfolioTakeoverView
    {
        $ids = [];
        foreach ($this->operations->activeOnDate($workspace, $takeover->accountId, $takeover->takenOverOn) as $operation) {
            if (OperationType::SECURITY_TRANSFER_IN === $operation->details->type || OperationType::DEPOSIT === $operation->details->type) {
                $ids[] = $operation->id;
            }
        }

        return new PortfolioTakeoverView($takeover, array_slice($ids, 0, PortfolioTakeoverView::MAX_OPERATIONS));
    }
}
