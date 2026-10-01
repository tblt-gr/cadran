<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Portfolio\Domain\Instrument;
use App\Module\Portfolio\Domain\InstrumentRepository;
use App\Module\Portfolio\Domain\InstrumentSource;
use App\Module\Portfolio\Domain\InstrumentStatus;
use App\Module\Portfolio\Domain\InvalidInstrument;
use Symfony\Component\Clock\ClockInterface;

final readonly class CreateInstrument
{
    public const int MAX_INSTRUMENTS = 500;

    public function __construct(
        private CallerWorkspaceContext $caller,
        private InstrumentRepository $instruments,
        private InstrumentInputParser $parser,
        private UuidGenerator $uuid,
        private TransactionBoundary $transactions,
        private RecordAuditEvent $audit,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(CreateInstrumentInput $input): InstrumentView
    {
        $context = $this->caller->resolveContext();
        $parsed = $this->parser->parse($input);

        return $this->transactions->transactional(function () use ($context, $parsed, $input): InstrumentView {
            $this->instruments->lockWorkspace($context->workspace);
            if ($this->instruments->countAll($context->workspace) >= self::MAX_INSTRUMENTS) {
                throw new InstrumentLimitReached('The workspace instrument limit is reached.');
            }
            $now = $this->clock->now();
            try {
                $instrument = new Instrument(
                    $this->uuid->generate(), $context->workspace, $parsed->name, $parsed->isin, $parsed->ticker,
                    $parsed->venueMic, $parsed->instrumentType, $parsed->assetClass, $parsed->region,
                    $parsed->currencyCode, $parsed->quantityPrecision, $parsed->pricePrecision,
                    InstrumentSource::MANUAL, $now, $context->actorId, InstrumentStatus::ACTIVE,
                    null, $now, $context->actorId, $now, 1,
                );
            } catch (InvalidInstrument $exception) {
                throw new InvalidInstrumentInput($exception->getMessage(), $exception->problemCode, $exception);
            }
            $this->assertNoDuplicate($instrument, $input->confirmDuplicate);
            $this->instruments->add($instrument);
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId, InstrumentAuditEvents::CREATED,
                InstrumentAuditEvents::ENTITY, $instrument->id, InstrumentAudit::created($instrument),
            ));

            return InstrumentView::fromInstrument($instrument);
        });
    }

    private function assertNoDuplicate(Instrument $instrument, bool $confirmed): void
    {
        if (null !== $instrument->isin && null !== ($existing = $this->instruments->findByIsin($instrument->workspace, $instrument->isin))) {
            throw new InstrumentIsinExists($existing->id);
        }
        $candidates = $this->instruments->duplicateCandidates($instrument->workspace, $instrument->ticker, $instrument->normalizedName, null, 5);
        if (!$confirmed && [] !== $candidates) {
            throw new InstrumentDuplicateCandidates(array_map(InstrumentCandidateView::fromInstrument(...), $candidates));
        }
    }
}
