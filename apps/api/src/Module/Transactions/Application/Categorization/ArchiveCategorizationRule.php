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

final readonly class ArchiveCategorizationRule
{
    public function __construct(private CallerWorkspaceContext $caller, private CategorizationRuleRepository $rules, private TransactionBoundary $boundary, private CategorizationWriteLock $writeLock, private RecordAuditEvent $audit, private PresentCategorizationRule $presenter, private ClockInterface $clock)
    {
    }

    /** @return array<string, mixed> */
    public function __invoke(string $id, int $version): array
    {
        $context = $this->caller->resolveContext();

        return $this->boundary->transactional(function () use ($context, $id, $version): array {
            $this->writeLock->acquire($context->workspace);
            $current = $this->rules->findForUpdate($context->workspace, $id) ?? throw new CategorizationRuleNotFound();
            if ($version !== $current->version) {
                throw new StaleCategorizationRule();
            }
            $archived = $current->archive($this->clock->now());
            if (!$this->rules->update($archived, $current->version)) {
                throw new StaleCategorizationRule();
            }
            ($this->audit)(new AuditEventRecord($context->workspace, $context->actorId, 'categorization_rule.archived', 'categorization_rule', $id, AuditDiff::change(['active' => $current->active], ['active' => false, 'archived' => true])));

            return $this->presenter->one($archived);
        });
    }
}
