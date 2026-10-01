<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Audit\Domain\AuditDiff;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Portfolio\Domain\InstrumentRepository;
use App\Module\Portfolio\Domain\InstrumentStatus;
use App\Module\Portfolio\Domain\InvalidInstrument;
use Symfony\Component\Clock\ClockInterface;

final readonly class ChangeInstrumentStatus
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private InstrumentRepository $instruments,
        private TransactionBoundary $transactions,
        private RecordAuditEvent $audit,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, int $version, InstrumentStatus $target): InstrumentView
    {
        $context = $this->caller->resolveContext();

        return $this->transactions->transactional(function () use ($context, $id, $version, $target): InstrumentView {
            $current = $this->instruments->findForUpdate($context->workspace, $id) ?? throw new InstrumentNotFound();
            if ($version !== $current->version) {
                throw new StaleInstrumentVersion('The instrument was changed by another request.');
            }
            try {
                $changed = InstrumentStatus::ACTIVE === $target
                    ? $current->reactivate($this->clock->now())
                    : $current->deactivate($this->clock->now());
            } catch (InvalidInstrument $exception) {
                throw new InvalidInstrumentInput($exception->getMessage(), $exception->problemCode, $exception);
            }
            if (!$this->instruments->update($changed, $current->version)) {
                throw new StaleInstrumentVersion('The instrument was changed by another request.');
            }
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId,
                InstrumentStatus::ACTIVE === $target ? InstrumentAuditEvents::REACTIVATED : InstrumentAuditEvents::DEACTIVATED,
                InstrumentAuditEvents::ENTITY, $changed->id,
                AuditDiff::change(['status' => $current->status->value], ['status' => $changed->status->value]),
            ));

            return InstrumentView::fromInstrument($changed);
        });
    }
}
