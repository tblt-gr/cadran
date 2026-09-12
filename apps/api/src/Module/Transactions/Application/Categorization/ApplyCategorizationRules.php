<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Transactions\Domain\Categorization\CategorizationPreviewRepository;
use App\Module\Transactions\Domain\CategorizationOrigin;
use App\Module\Transactions\Domain\TransactionRepository;
use App\Module\Transactions\Domain\TransactionSplit;
use Symfony\Component\Clock\ClockInterface;

final readonly class ApplyCategorizationRules
{
    public function __construct(private CallerWorkspaceContext $caller, private CategorizationPreviewRepository $previews, private TransactionRepository $transactions, private \App\Module\Transactions\Domain\Categorization\CategorizationRuleRepository $rules, private BuildCategorizationRun $buildRun, private UuidGenerator $ids, private TransactionBoundary $boundary, private RecordAuditEvent $audit, private ClockInterface $clock)
    {
    }

    /** @return array{changed: int, skippedManual: int, deactivatedRuleIds: list<string>} */
    public function __invoke(string $token): array
    {
        $context = $this->caller->resolveContext();

        return $this->boundary->transactional(function () use ($context, $token): array {
            $now = $this->clock->now();
            $preview = $this->previews->findLiveForUpdate($context->workspace, $token, $now) ?? throw new PreviewStale();
            $transactions = $this->transactions->listForCategorization($context->workspace, $preview->from, $preview->to, PreviewCategorizationRules::MAX_TRANSACTIONS + 1, true);
            if (count($transactions) > PreviewCategorizationRules::MAX_TRANSACTIONS) {
                throw new PreviewStale();
            }
            $run = ($this->buildRun)($context->workspace, $transactions, $context->actorId, $now);
            if (!hash_equals($preview->token, $run->token($preview->ruleId, $preview->from, $preview->to))) {
                throw new PreviewStale();
            }
            $changed = $skippedManual = 0;
            $increments = [];
            foreach ($transactions as $transaction) {
                $resolution = $run->resolutions[$transaction->id];
                $selectedMatches = null === $preview->ruleId ? null !== $resolution->winner : in_array($preview->ruleId, $resolution->matchingRuleIds, true);
                if (!$selectedMatches) {
                    continue;
                }
                if (array_any($transaction->splits, static fn ($split): bool => CategorizationOrigin::MANUAL === $split->origin)) {
                    ++$skippedManual;
                    continue;
                }
                $winner = $resolution->winner;
                if (null === $winner || (null !== $preview->ruleId && $winner->id !== $preview->ruleId)) {
                    continue;
                }
                $existing = $transaction->splits[0] ?? null;
                $axes = array_map(static fn ($axis): string => $axis->value, $winner->targetAxes);
                $existingAxes = null === $existing ? [] : array_map(static fn ($axis): string => $axis->value, $existing->analyticAxes);
                if (null !== $existing && $existing->ruleId === $winner->id && $existing->categoryId === $winner->targetCategoryId && $existingAxes === $axes) {
                    continue;
                }
                $splitId = null === $existing ? $this->ids->generate() : $existing->id;
                $splitCreatedAt = null === $existing ? $now : $existing->createdAt;
                $split = new TransactionSplit($splitId, $context->workspace, $transaction->id, $winner->targetCategoryId, $transaction->amount, $winner->targetAxes, null, $splitCreatedAt, 0, CategorizationOrigin::RULE, $winner->id);
                $updated = $transaction->categorize($split, $winner->targetCounterparty, $now);
                if (!$this->transactions->update($updated, $transaction->version)) {
                    throw new PreviewStale();
                }
                ++$changed;
                $increments[$winner->id] = ($increments[$winner->id] ?? 0) + 1;
            }
            foreach ($increments as $ruleId => $count) {
                $this->rules->incrementAppliedCount($context->workspace, $ruleId, $count);
            }
            $this->previews->markConsumed($context->workspace, $preview->id, $now);
            ($this->audit)(new AuditEventRecord($context->workspace, $context->actorId, 'categorization_rule.applied', 'categorization_rule_run', $preview->id, AuditDiff::creation(['changed' => $changed, 'skippedManual' => $skippedManual])));

            return ['changed' => $changed, 'skippedManual' => $skippedManual, 'deactivatedRuleIds' => $run->deactivatedRuleIds];
        });
    }
}
