<?php

declare(strict_types=1);

namespace App\Module\Transactions\Application\Categorization;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Transactions\Domain\Categorization\CategorizationRuleRepository;
use Symfony\Component\Clock\ClockInterface;

final readonly class UpdateCategorizationRule
{
    public function __construct(private CallerWorkspaceContext $caller, private CategorizationRuleRepository $rules, private CategorizationRuleFactory $factory, private TransactionBoundary $boundary, private RecordAuditEvent $audit, private PresentCategorizationRule $presenter, private ClockInterface $clock)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(string $id, CategorizationRuleInput $input): array
    {
        $context = $this->caller->resolveContext();

        return $this->boundary->transactional(function () use ($context, $id, $input): array {
            $current = $this->rules->findForUpdate($context->workspace, $id) ?? throw new CategorizationRuleNotFound();
            if ($input->version !== $current->version) {
                throw new StaleCategorizationRule();
            }
            if (null !== $current->archivedAt) {
                throw new StaleCategorizationRule('archived');
            }
            $updated = $this->factory->revise($current, $input, $this->clock->now());
            if (!$this->rules->update($updated, $current->version)) {
                throw new StaleCategorizationRule();
            }
            ($this->audit)(new AuditEventRecord($context->workspace, $context->actorId, 'categorization_rule.updated', 'categorization_rule', $id, AuditDiff::change(['active' => $current->active, 'priority' => $current->priority], ['active' => $updated->active, 'priority' => $updated->priority])));

            return $this->presenter->one($updated);
        });
    }
}
