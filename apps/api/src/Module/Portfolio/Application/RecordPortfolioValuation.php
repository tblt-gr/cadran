<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Accounts\Application\AssertPeriodOpen;
use App\Module\Accounts\Application\PublishCalculatedAccountValuation;
use App\Module\Accounts\Application\WithdrawCalculatedAccountValuation;
use App\Module\Accounts\Domain\Account;
use App\Module\Accounts\Domain\AccountRepository;
use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Application\WorkspaceContext;
use App\Module\Foundation\Domain\AssetAmount;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Portfolio\Domain\InstrumentRepository;
use App\Module\Portfolio\Domain\Valuation\CashSource;
use App\Module\Portfolio\Domain\Valuation\PositionSnapshot;
use App\Module\Portfolio\Domain\Valuation\PriceSource;
use App\Module\Portfolio\Domain\Valuation\Valuation;
use App\Module\Portfolio\Domain\Valuation\ValuationCompleteness;
use App\Module\Portfolio\Domain\Valuation\ValuationRepository;
use App\Module\Portfolio\Domain\Valuation\ValuationRules;
use App\Module\Portfolio\Domain\Valuation\ValuationSource;
use Symfony\Component\Clock\ClockInterface;

/**
 * Records a valuation inside one transaction: period guard, account lock, validated figures, supersession of the
 * active valuation of the day, frozen position snapshots and the publication to net worth.
 */
final readonly class RecordPortfolioValuation
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private ValuationResolver $resolver,
        private ValuationRepository $valuations,
        private AccountRepository $accounts,
        private InstrumentRepository $instruments,
        private PublishCalculatedAccountValuation $publish,
        private WithdrawCalculatedAccountValuation $withdraw,
        private AssertPeriodOpen $assertPeriodOpen,
        private TransactionBoundary $transactions,
        private WorkspaceCalendar $calendar,
        private UuidGenerator $uuid,
        private RecordAuditEvent $audit,
        private PortfolioValuationPresenter $presenter,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $accountId, ValuationInput $input): ValuationView
    {
        $context = $this->caller->resolveContext();
        $valuedOn = ValuationResolver::parseDay($input->valuedOn, 'invalid_valuation_date');

        $valuation = $this->transactions->transactional(function () use ($context, $accountId, $input, $valuedOn): Valuation {
            // Same order as every dated write: the closing guard first, then the account lock.
            ($this->assertPeriodOpen)($context->workspace, $valuedOn);
            $account = $this->gate->forWrite($context->workspace, $accountId, true);
            $resolved = $this->resolver->resolve($account, $input, $this->calendar->today());
            $current = $this->valuations->findActive($context->workspace, $account->id, $valuedOn);
            $this->assertSupersession($context, $account, $current, $input->version);
            $now = $this->clock->now();
            $this->freeze($account, $resolved, $now);

            $valuation = $this->build($context, $account, $resolved, $current, $now);
            $flags = $valuation->flags($resolved->displayPrecision);
            if (null !== $current) {
                $superseded = $current->supersededAt($now, false);
                if (!$this->valuations->markSuperseded($superseded, $current->version)) {
                    throw new ValuationConflict(ValuationConflict::CONCURRENT_CHANGE);
                }
                ($this->audit)(new AuditEventRecord(
                    $context->workspace, $context->actorId, PortfolioValuationAuditEvents::SUPERSEDED,
                    PortfolioValuationAuditEvents::ENTITY, $superseded->id,
                    PortfolioValuationAudit::superseded($superseded, $valuation->id, $current->flags($resolved->displayPrecision)),
                ));
            }
            $this->valuations->add($valuation);
            $this->publishToNetWorth($context, $valuation);
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId, PortfolioValuationAuditEvents::RECORDED,
                PortfolioValuationAuditEvents::ENTITY, $valuation->id, PortfolioValuationAudit::recorded($valuation, $flags),
            ));

            return $valuation;
        });

        return $this->presenter->present($context->workspace, [$valuation])[0];
    }

    private function assertSupersession(WorkspaceContext $context, Account $account, ?Valuation $current, ?int $version): void
    {
        if (null === $current) {
            if (null !== $version) {
                throw new ValuationConflict(ValuationConflict::STALE_VERSION);
            }
            if ($this->valuations->countActive($context->workspace, $account->id) >= ValuationRules::MAX_ACTIVE_PER_ACCOUNT) {
                throw new ValuationConflict(ValuationConflict::LIMIT_REACHED);
            }

            return;
        }
        if (null === $version) {
            throw new ValuationConflict(ValuationConflict::EXISTS);
        }
        if ($current->version !== $version) {
            throw new ValuationConflict(ValuationConflict::STALE_VERSION);
        }
    }

    private function build(WorkspaceContext $context, Account $account, ResolvedValuation $resolved, ?Valuation $current, \DateTimeImmutable $now): Valuation
    {
        $id = $this->uuid->generate();
        $figures = $resolved->figures;
        $snapshots = array_map(fn ($line): PositionSnapshot => new PositionSnapshot(
            $this->uuid->generate(), $id, $line->instrumentId, $line->quantity, $line->unitPrice, $line->priceDate,
            null === $line->unitPrice ? null : PriceSource::MANUAL, $line->marketValue, $line->averageCostBasis, $line->priceQuality,
        ), $figures->lines);

        return new Valuation(
            id: $id, workspace: $context->workspace, accountId: $account->id, valuedOn: $resolved->valuedOn, assetCode: $account->assetCode,
            cash: $figures->cash, derivedCash: $resolved->derivedCash,
            cashSource: $figures->cashObserved ? CashSource::OBSERVED : CashSource::DERIVED,
            securitiesValue: $figures->securitiesValue, totalValue: $figures->totalValue, observedTotal: $resolved->observedTotal,
            detail: $resolved->detail, completeness: $figures->completeness, source: ValuationSource::MANUAL, note: $resolved->note,
            supersedesId: $current?->id, supersededAt: null, withdrawn: false, recordedAt: $now, recordedBy: $context->actorId,
            version: 1, positions: $snapshots,
        );
    }

    private function publishToNetWorth(WorkspaceContext $context, Valuation $valuation): void
    {
        if (ValuationCompleteness::COMPLETE === $valuation->completeness && null !== $valuation->totalValue) {
            ($this->publish)($context->workspace, $valuation->accountId, $valuation->valuedOn, new AssetAmount($valuation->totalValue, $valuation->assetCode));

            return;
        }
        // A partial valuation publishes nothing, and no longer lets the one it replaces stand.
        ($this->withdraw)($context->workspace, $valuation->accountId, $valuation->valuedOn);
    }

    private function freeze(Account $account, ResolvedValuation $resolved, \DateTimeImmutable $now): void
    {
        $usedAccount = $account->markUsed($now);
        if ($usedAccount !== $account && !$this->accounts->update($usedAccount, $account->version)) {
            throw new ValuationConflict(ValuationConflict::CONCURRENT_CHANGE);
        }
        $ids = array_map(static fn ($instrument): string => $instrument->id, $resolved->instruments);
        // A fixed order keeps two writers naming the same instruments from deadlocking.
        sort($ids);
        foreach ($ids as $id) {
            $instrument = $this->instruments->findForUpdate($account->workspace, $id);
            $used = $instrument?->markUsed($now);
            if (null !== $instrument && null !== $used && $used !== $instrument && !$this->instruments->update($used, $instrument->version)) {
                throw new ValuationConflict(ValuationConflict::CONCURRENT_CHANGE);
            }
        }
    }
}
