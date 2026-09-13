<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Transactions\Domain\Categorization\CategorizationRule;
use App\Module\Transactions\Domain\Transaction;

final readonly class CategorizationRun
{
    /**
     * @param list<CategorizationRule>      $rules
     * @param list<Transaction>             $transactions
     * @param array<string, RuleResolution> $resolutions
     * @param list<string>                  $deactivatedRuleIds
     */
    public function __construct(public string $workspaceId, public array $rules, public array $transactions, public array $resolutions, public array $deactivatedRuleIds, public bool $executionLimitExceeded = false)
    {
    }

    public function token(?string $ruleId, \DateTimeImmutable $from, \DateTimeImmutable $to): string
    {
        $matched = [];
        foreach ($this->transactions as $transaction) {
            $resolution = $this->resolutions[$transaction->id];
            if (null !== $resolution->winner || (null !== $ruleId && in_array($ruleId, $resolution->matchingRuleIds, true))) {
                $matched[] = [$transaction->id, $transaction->version];
            }
        }

        return hash('sha256', json_encode([
            'workspaceId' => $this->workspaceId, 'ruleId' => $ruleId, 'from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'),
            'transactions' => $matched,
            'rules' => array_map(static fn (CategorizationRule $rule): array => [$rule->id, $rule->version], $this->rules),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
