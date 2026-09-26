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
use App\Module\Reporting\Domain\MetricPolicyActivation;
use App\Module\Reporting\Domain\MetricPolicyRepository;
use App\Module\Reporting\Domain\MetricPolicyResolver;

/**
 * Appends an activation. It never edits a version or a past activation, and a
 * closed month keeps the version it was closed under.
 */
final readonly class ActivateMetricPolicy
{
    public const int MAX_REASON_LENGTH = 200;

    public function __construct(
        private CallerWorkspaceContext $caller,
        private MetricPolicyRepository $policies,
        private TransactionBoundary $transactionBoundary,
        private RecordAuditEvent $recordAuditEvent,
        private UuidGenerator $uuidGenerator,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(ActivateMetricPolicyInput $input): MetricPolicyCatalogView
    {
        $context = $this->caller->resolveContext();
        if (!$context->isOwner) {
            throw new WorkspaceAccessDenied('Only the workspace owner may activate a metric policy.');
        }
        $reason = null === $input->reason ? null : trim($input->reason);
        if (null !== $reason && mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new InvalidMetricPolicyInput('An activation reason holds at most 200 characters.');
        }
        $reason = '' === $reason ? null : $reason;

        return $this->transactionBoundary->transactional(function () use ($context, $input, $reason): MetricPolicyCatalogView {
            $this->policies->lock($context->workspace);
            $target = $this->policies->find($context->workspace, $input->version)
                ?? throw new UnknownMetricPolicyVersion('This metric policy version does not exist in this workspace.');
            $now = $this->calendar->now();
            $activations = $this->policies->activations($context->workspace);
            if (count($activations) >= MetricPolicyRepository::MAX_ACTIVATIONS) {
                throw new MetricPolicyActivationLimit('The metric policy activation history is full.');
            }
            $current = MetricPolicyResolver::forMonth(null, $now, $activations);
            if ($input->expectedActiveVersion !== $current) {
                throw new StaleActiveMetricPolicy('The active metric policy changed since it was read.');
            }
            if ($target->version === $current) {
                throw new MetricPolicyAlreadyActive('This metric policy version is already active.');
            }

            $activeFrom = $now;
            if ([] !== $activations) {
                $latest = $activations[array_key_last($activations)]->activeFrom;
                if ($latest >= $activeFrom) {
                    $activeFrom = $latest->modify('+1 microsecond');
                }
            }
            $activation = new MetricPolicyActivation($target->version, $activeFrom, $reason, $context->actorId);
            $id = $this->uuidGenerator->generate();
            $this->policies->addActivation($context->workspace, $id, $activation);
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: MetricPolicyAuditEvents::ACTIVATED,
                entityType: MetricPolicyAuditEvents::ENTITY,
                entityId: $id,
                diff: AuditDiff::change(
                    ['version' => $current],
                    [
                        'version' => $target->version,
                        'excludedKinds' => implode(',', array_map(static fn (AccountKind $kind): string => $kind->value, $target->cashExcludedAccountKinds)),
                    ],
                ),
            ));

            return new MetricPolicyCatalogView(
                $target->version,
                MetricPolicyView::of($target),
                $activeFrom->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM),
                [],
            );
        });
    }
}
