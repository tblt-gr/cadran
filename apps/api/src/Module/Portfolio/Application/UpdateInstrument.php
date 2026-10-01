<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Portfolio\Domain\InstrumentRepository;
use App\Module\Portfolio\Domain\InvalidInstrument;
use Symfony\Component\Clock\ClockInterface;

final readonly class UpdateInstrument
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private InstrumentRepository $instruments,
        private InstrumentInputParser $parser,
        private TransactionBoundary $transactions,
        private RecordAuditEvent $audit,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $id, UpdateInstrumentInput $input): InstrumentView
    {
        $context = $this->caller->resolveContext();

        return $this->transactions->transactional(function () use ($context, $id, $input): InstrumentView {
            $this->instruments->lockWorkspace($context->workspace);
            $current = $this->instruments->findForUpdate($context->workspace, $id) ?? throw new InstrumentNotFound();
            if ($input->version !== $current->version) {
                throw new StaleInstrumentVersion('The instrument was changed by another request.');
            }
            $parsed = $this->parser->parse($input, $current);
            try {
                $updated = $current->editManually(
                    $parsed->name, $parsed->isin, $parsed->ticker, $parsed->venueMic, $parsed->instrumentType,
                    $parsed->assetClass, $parsed->region, $parsed->currencyCode, $parsed->quantityPrecision,
                    $parsed->pricePrecision, $context->actorId, $this->clock->now(),
                );
            } catch (InvalidInstrument $exception) {
                throw new InvalidInstrumentInput($exception->getMessage(), $exception->problemCode, $exception);
            }
            if (null !== $updated->isin && null !== ($existing = $this->instruments->findByIsin($context->workspace, $updated->isin, $updated->id))) {
                throw new InstrumentIsinExists($existing->id);
            }
            $candidates = $this->instruments->duplicateCandidates($context->workspace, $updated->ticker, $updated->normalizedName, $updated->id, 5);
            if (!$input->confirmDuplicate && [] !== $candidates) {
                throw new InstrumentDuplicateCandidates(array_map(InstrumentCandidateView::fromInstrument(...), $candidates));
            }
            if (!$this->instruments->update($updated, $current->version)) {
                throw new StaleInstrumentVersion('The instrument was changed by another request.');
            }
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId, InstrumentAuditEvents::UPDATED,
                InstrumentAuditEvents::ENTITY, $updated->id, InstrumentAudit::changedFields($current, $updated),
            ));

            return InstrumentView::fromInstrument($updated);
        });
    }
}
