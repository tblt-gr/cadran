<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Transactions\Domain\Categorization\CategorizationRuleRepository;
use Symfony\Component\Clock\ClockInterface;

final readonly class CreateCategorizationRule
{
    public function __construct(private CallerWorkspaceContext $caller, private CategorizationRuleRepository $rules, private CategorizationRuleFactory $factory, private UuidGenerator $ids, private TransactionBoundary $boundary, private RecordAuditEvent $audit, private PresentCategorizationRule $presenter, private ClockInterface $clock)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(CategorizationRuleInput $input): array
    {
        $context = $this->caller->resolveContext();

        return $this->boundary->transactional(function () use ($context, $input): array {
            $rule = $this->factory->create($this->ids->generate(), $context->workspace, $input, $this->clock->now());
            $this->rules->add($rule);
            ($this->audit)(new AuditEventRecord($context->workspace, $context->actorId, 'categorization_rule.created', 'categorization_rule', $rule->id, AuditDiff::creation(['active' => true, 'priority' => $rule->priority])));

            return $this->presenter->one($rule);
        });
    }
}
