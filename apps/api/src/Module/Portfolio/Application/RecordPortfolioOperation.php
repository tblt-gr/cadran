<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Portfolio\Domain\PortfolioOperation;
use Symfony\Component\Clock\ClockInterface;

final readonly class RecordPortfolioOperation
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private OperationResolver $resolver,
        private PortfolioOperationRecorder $recorder,
        private PortfolioOperationPresenter $presenter,
        private AssertPeriodOpen $assertPeriodOpen,
        private TransactionBoundary $transactions,
        private WorkspaceCalendar $calendar,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $accountId, OperationInput $input): OperationView
    {
        $context = $this->caller->resolveContext();
        $tradeDate = OperationResolver::parseTradeDate($input->tradeDate);

        $operation = $this->transactions->transactional(function () use ($context, $accountId, $input, $tradeDate): PortfolioOperation {
            // Same order as every dated write: the closing guard first, then the account lock.
            ($this->assertPeriodOpen)($context->workspace, $tradeDate);
            $account = $this->gate->forWrite($context->workspace, $accountId, true);
            $resolved = $this->resolver->resolve($account, $input, $this->calendar->today(), true);

            return $this->recorder->record($context, $account, $resolved, $this->clock->now());
        });

        return $this->presenter->present($context->workspace, [$operation])[0];
    }
}
