<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Transactions\Domain\Categorization\CategorizationPreview;
use App\Module\Transactions\Domain\Categorization\CategorizationPreviewRepository;
use App\Module\Transactions\Domain\Categorization\CategorizationRuleRepository;
use App\Module\Transactions\Domain\CategorizationOrigin;
use App\Module\Transactions\Domain\TransactionRepository;
use Symfony\Component\Clock\ClockInterface;

final readonly class PreviewCategorizationRules
{
    public const int MAX_TRANSACTIONS = 5_000;

    public function __construct(private CallerWorkspaceContext $caller, private TransactionRepository $transactions, private CategorizationRuleRepository $rules, private CategorizationPreviewRepository $previews, private BuildCategorizationRun $buildRun, private UuidGenerator $ids, private TransactionBoundary $boundary, private ClockInterface $clock)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(?string $ruleId, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        if ($to < $from) {
            throw new InvalidCategorizationRuleInput();
        }
        $context = $this->caller->resolveContext();

        return $this->boundary->transactional(function () use ($context, $ruleId, $from, $to): array {
            if (null !== $ruleId && null === $this->rules->find($context->workspace, $ruleId)) {
                throw new CategorizationRuleNotFound();
            }
            $transactions = $this->transactions->listForCategorization($context->workspace, $from, $to, self::MAX_TRANSACTIONS + 1, false);
            if (count($transactions) > self::MAX_TRANSACTIONS) {
                throw new CategorizationRangeTooLarge();
            }
            $now = $this->clock->now();
            $run = ($this->buildRun)($context->workspace, $transactions, $context->actorId, $now);
            $token = $run->token($ruleId, $from, $to);
            $this->previews->purgeExpired($context->workspace, $now);
            $this->previews->add(new CategorizationPreview($this->ids->generate(), $context->workspace, $token, $ruleId, $from, $to, $now, $now->modify(CategorizationPreview::LIFETIME)));

            return $this->report($run, $ruleId, $token);
        });
    }

    /** @return array<string, mixed> */
    private function report(CategorizationRun $run, ?string $selectedRuleId, string $token): array
    {
        $matched = $wouldChange = $skippedManual = 0;
        $conflicts = $samples = $skipped = [];
        foreach ($run->transactions as $transaction) {
            $resolution = $run->resolutions[$transaction->id];
            $selectedMatches = null === $selectedRuleId ? null !== $resolution->winner : in_array($selectedRuleId, $resolution->matchingRuleIds, true);
            if (!$selectedMatches) {
                continue;
            }
            ++$matched;
            $manual = array_any($transaction->splits, static fn ($split): bool => CategorizationOrigin::MANUAL === $split->origin);
            if ($manual) {
                ++$skippedManual;
                $skipped[] = ['transactionId' => $transaction->id, 'bookedOn' => $transaction->bookedOn->format('Y-m-d'), 'reason' => 'MANUAL_CATEGORIZATION'];
                continue;
            }
            if (null === $selectedRuleId && count($resolution->matchingRuleIds) > 1
                && \App\Module\Transactions\Domain\TransactionSource::MANUAL === $transaction->source) {
                $conflicts[] = ['transactionId' => $transaction->id, 'ruleIds' => $resolution->matchingRuleIds];
            }
            $winner = $resolution->winner;
            if (null === $winner || (null !== $selectedRuleId && $winner->id !== $selectedRuleId)) {
                continue;
            }
            $existing = $transaction->splits[0] ?? null;
            $axes = array_map(static fn ($axis): string => $axis->value, $winner->targetAxes);
            $existingAxes = null === $existing ? [] : array_map(static fn ($axis): string => $axis->value, $existing->analyticAxes);
            if (null !== $existing && $existing->ruleId === $winner->id && $existing->categoryId === $winner->targetCategoryId && $existingAxes === $axes) {
                continue;
            }
            ++$wouldChange;
            if (count($samples) < 20) {
                $samples[] = ['transactionId' => $transaction->id, 'bookedOn' => $transaction->bookedOn->format('Y-m-d'), 'currentCategoryId' => $existing?->categoryId, 'targetCategoryId' => $winner->targetCategoryId];
            }
        }

        return ['previewToken' => $token, 'matched' => $matched, 'wouldChange' => $wouldChange, 'skippedManual' => $skippedManual, 'conflicts' => $conflicts, 'samples' => $samples, 'skipped' => array_slice($skipped, 0, 20), 'deactivatedRuleIds' => $run->deactivatedRuleIds];
    }
}
