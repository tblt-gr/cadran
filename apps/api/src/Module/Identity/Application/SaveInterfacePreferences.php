<?php

declare(strict_types=1);

namespace App\Module\Identity\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\WorkspaceAccessDenied;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Identity\Domain\InterfacePreferencesRepository;

/**
 * Stores the interface preferences of the calling workspace under an optimistic
 * version, so a second open tab cannot silently overwrite a newer choice.
 */
final readonly class SaveInterfacePreferences
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private InterfacePreferencesRepository $preferences,
        private TransactionManager $transactionManager,
        private RecordAuditEvent $recordAuditEvent,
        private WorkspaceCalendar $calendar,
    ) {
    }

    public function __invoke(SaveInterfacePreferencesInput $input): InterfacePreferencesView
    {
        $context = $this->caller->resolveContext();
        if (!$context->isOwner) {
            throw new WorkspaceAccessDenied('Only the workspace owner may change interface preferences.');
        }
        if ($input->version < 0) {
            throw new InvalidInterfacePreferencesInput('An interface preference version is never negative.');
        }

        return $this->transactionManager->transactional(function () use ($context, $input): InterfacePreferencesView {
            $before = $this->preferences->find($context->workspace);
            if ($before->version !== $input->version) {
                throw new StaleInterfacePreferencesVersion('The interface preferences changed since they were read.');
            }
            $saved = $before->saved($input->includeUpcoming, $this->calendar->now());
            if (!$this->preferences->save($saved, $input->version)) {
                throw new StaleInterfacePreferencesVersion('The interface preferences changed since they were read.');
            }
            ($this->recordAuditEvent)(new AuditEventRecord(
                workspace: $context->workspace,
                actorId: $context->actorId,
                eventType: IdentityAuditEvents::INTERFACE_PREFERENCES_UPDATED,
                entityType: IdentityAuditEvents::ENTITY_WORKSPACE,
                entityId: $context->workspace->id,
                diff: AuditDiff::change(
                    ['includeUpcoming' => $before->includeUpcoming],
                    ['includeUpcoming' => $saved->includeUpcoming],
                ),
            ));

            return InterfacePreferencesView::of($saved);
        });
    }
}
