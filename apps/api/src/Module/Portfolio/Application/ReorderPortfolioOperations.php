<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Portfolio\Domain\PortfolioOperationRepository;
use Symfony\Component\Clock\ClockInterface;

final readonly class ReorderPortfolioOperations
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private PortfolioOperationRepository $operations,
        private AssertPeriodOpen $assertPeriodOpen,
        private ReconcilePortfolioPositions $reconcile,
        private LifeInsurance\ReconcileContractOperations $contractOperations,
        private ClockInterface $clock,
        private TransactionBoundary $transactions,
        private RecordAuditEvent $audit,
        private WorkspaceCalendar $calendar,
    ) {
    }

    /** @param list<string> $operationIds */
    public function __invoke(string $accountId, string $tradeDate, array $operationIds): PortfolioOperationOrderView
    {
        $context = $this->caller->resolveContext();
        $date = ReadPortfolioPositions::date($tradeDate);
        if ($date > $this->calendar->today() || [] === $operationIds || count($operationIds) !== count(array_unique($operationIds))) {
            throw new InvalidPositionQuery('An operation order has a valid past date and unique identifiers.');
        }
        foreach ($operationIds as $id) {
            if (1 !== preg_match(self::UUID_PATTERN, $id)) {
                throw new InvalidPositionQuery('An operation order contains canonical identifiers.');
            }
        }

        return $this->transactions->transactional(function () use ($context, $accountId, $date, $operationIds): PortfolioOperationOrderView {
            ($this->assertPeriodOpen)($context->workspace, $date);
            $this->gate->forWrite($context->workspace, $accountId, true);
            $current = $this->operations->activeOnDate($context->workspace, $accountId, $date);
            $currentIds = array_map(static fn ($operation): string => $operation->id, $current);
            $expected = $currentIds;
            $submitted = $operationIds;
            sort($expected);
            sort($submitted);
            if ($expected !== $submitted) {
                throw new StalePortfolioOperationOrder();
            }
            $sequences = [];
            foreach ($operationIds as $index => $id) {
                $sequences[$id] = $index + 1;
            }
            $this->operations->reorder($context->workspace, $accountId, $date, $sequences);
            ($this->reconcile)($context->workspace, $accountId);
            ($this->contractOperations)($context, $accountId, $this->clock->now());
            ($this->audit)(new AuditEventRecord(
                $context->workspace,
                $context->actorId,
                PortfolioOperationAuditEvents::REORDERED,
                PortfolioOperationAuditEvents::ENTITY,
                $accountId,
                PortfolioOperationAudit::reordered($accountId, $operationIds),
            ));

            return new PortfolioOperationOrderView($date->format('Y-m-d'), $operationIds);
        });
    }
}
