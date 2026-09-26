<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Catalog\Domain\AccountKind;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Reporting\Domain\InvalidMetricPolicy;
use App\Module\Reporting\Domain\MetricPolicy;
use App\Module\Reporting\Domain\MetricPolicyRepository;

/** Appends the next immutable metric policy version of the caller's workspace. */
final readonly class CreateMetricPolicy
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private MetricPolicyRepository $policies,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private UuidGenerator $uuidGenerator,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(CreateMetricPolicyInput $input): MetricPolicyView
    {
        $context = $this->caller->resolveContext();
        if (!$context->isOwner) {
            throw new WorkspaceAccessDenied('Only the workspace owner may create a metric policy.');
        }
        $kinds = [];
        foreach ($input->cashExcludedAccountKinds as $value) {
            $kinds[] = AccountKind::tryFrom($value) ?? throw new InvalidMetricPolicyInput('An excluded account kind must be a known account kind.');
        }

        return $this->transactionBoundary->transactional(function () use ($context, $input, $kinds): MetricPolicyView {
            $this->policies->lock($context->workspace);
            if ($this->policies->countStored($context->workspace) >= MetricPolicyRepository::MAX_VERSIONS) {
                throw new MetricPolicyVersionLimit('A workspace holds at most 100 metric policy versions.');
            }
            $stored = $this->policies->listStored($context->workspace);
            $latest = $this->policies->latestVersion($context->workspace);
            try {
                $policy = MetricPolicy::create($latest + 1, $input->label, $kinds, $this->calendar->now(), $context->actorId);
            } catch (InvalidMetricPolicy $exception) {
                throw new InvalidMetricPolicyInput($exception->getMessage(), previous: $exception);
            }
            foreach ([MetricPolicy::systemV1(), ...$stored] as $existing) {
                if ($existing->sameDefinitionAs($policy)) {
                    throw new MetricPolicyDefinitionExists($existing->version);
                }
            }

            $id = $this->uuidGenerator->generate();
            $this->policies->add($context->workspace, $id, $policy);
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: MetricPolicyAuditEvents::CREATED,
                entityType: MetricPolicyAuditEvents::ENTITY,
                entityId: $id,
                diff: AuditDiff::creation([
                    'version' => $policy->version,
                    'excludedKinds' => implode(',', array_map(static fn (AccountKind $kind): string => $kind->value, $policy->cashExcludedAccountKinds)),
                ]),
            ));

            return MetricPolicyView::of($policy);
        });
    }
}
