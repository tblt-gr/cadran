<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Categories\Application\CategoryArchivalSideEffect;
use App\Module\Foundation\Domain\WorkspaceScope;
use App\Module\Transactions\Domain\Categorization\CategorizationRuleRepository;
use App\Module\Transactions\Domain\Categorization\RuleDeactivationReason;
use Symfony\Component\DependencyInjection\Attribute\AsAlias;

#[AsAlias(CategoryArchivalSideEffect::class)]
final readonly class DeactivateRulesForArchivedCategory implements CategoryArchivalSideEffect
{
    public function __construct(private CategorizationRuleRepository $rules, private RecordAuditEvent $audit)
    {
    }

    public function apply(WorkspaceScope $workspace, string $categoryId, ?string $actorId, \DateTimeImmutable $at): void
    {
        foreach ($this->rules->activeTargetingForUpdate($workspace, $categoryId) as $current) {
            $deactivated = $current->deactivate(RuleDeactivationReason::CATEGORY_ARCHIVED, $at);
            if (!$this->rules->update($deactivated, $current->version)) {
                throw new StaleCategorizationRule();
            }
            ($this->audit)(new AuditEventRecord($workspace, $actorId, 'categorization_rule.deactivated', 'categorization_rule', $current->id, AuditDiff::change(['active' => true], ['active' => false, 'reason' => RuleDeactivationReason::CATEGORY_ARCHIVED->value])));
        }
    }
}
