<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Transactions\Domain\Categorization\CategorizationRuleRepository;
use App\Module\Transactions\Domain\CategorizationOrigin;
use App\Module\Transactions\Domain\Transaction;
use App\Module\Transactions\Domain\TransactionSplit;

final readonly class AutoCategorizeTransaction
{
    public function __construct(private BuildCategorizationRun $buildRun, private CategorizationRuleRepository $rules, private UuidGenerator $ids)
    {
    }

    public function __invoke(Transaction $transaction, ?string $actorId, \DateTimeImmutable $now): Transaction
    {
        $run = ($this->buildRun)($transaction->workspace, [$transaction], $actorId, $now);
        $winner = $run->resolutions[$transaction->id]->winner;
        if (null === $winner) {
            return $transaction;
        }
        $split = new TransactionSplit(
            $this->ids->generate(), $transaction->workspace, $transaction->id, $winner->targetCategoryId,
            $transaction->amount, $winner->targetAxes, null, $now, 0, CategorizationOrigin::RULE, $winner->id,
        );
        $this->rules->incrementAppliedCount($transaction->workspace, $winner->id, 1);

        return $transaction->categorize($split, $winner->targetCounterparty, $now, false);
    }
}
