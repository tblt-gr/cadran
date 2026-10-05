<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Application\WorkspaceCalendar;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Portfolio\Application\PortfolioAccountGate;
use App\Module\Portfolio\Domain\InstrumentRepository;
use App\Module\Portfolio\Domain\InstrumentType;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRate;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRateBasis;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRateRepository;
use App\Module\Portfolio\Domain\LifeInsurance\InvalidLifeInsurance;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContractRepository;
use Symfony\Component\Clock\ClockInterface;

/** Records the rate an insurer announced for one euro fund and one year: informative, never a performance. */
final readonly class RecordEuroFundRate
{
    public const int MAX_PER_CONTRACT = 200;
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private LifeInsuranceContractRepository $contracts,
        private EuroFundRateRepository $rates,
        private InstrumentRepository $instruments,
        private UuidGenerator $uuid,
        private TransactionBoundary $transactions,
        private RecordAuditEvent $audit,
        private WorkspaceCalendar $calendar,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $accountId, EuroFundRateInput $input): EuroFundRate
    {
        $context = $this->caller->resolveContext();

        return $this->transactions->transactional(function () use ($context, $accountId, $input): EuroFundRate {
            $account = $this->gate->forLifeInsurance($context->workspace, $accountId, true);
            $found = $this->contracts->findByAccount($context->workspace, $account->id) ?? throw new LifeInsuranceNotFound('life_insurance_contract_not_found');
            $contract = $this->contracts->findForUpdate($context->workspace, $found->id) ?? throw new LifeInsuranceNotFound('life_insurance_contract_not_found');

            $instrument = 1 === preg_match(self::UUID_PATTERN, $input->instrumentId) ? $this->instruments->findForUpdate($context->workspace, $input->instrumentId) : null;
            if (null === $instrument || InstrumentType::EURO_FUND !== $instrument->instrumentType) {
                throw new InvalidLifeInsurance('The support is not a euro fund of the workspace.', 'instrument_not_euro_fund');
            }
            $contract->assertRateYear($input->year, (int) $this->calendar->today()->format('Y'));
            $rate = new EuroFundRate(
                $this->uuid->generate(), $context->workspace, $contract->id, $instrument->id, $input->year,
                LifeInsuranceInputParser::requiredDecimal($input->servedRate, 'invalid_euro_fund_rate'),
                LifeInsuranceInputParser::decimal($input->bonusRate, 'invalid_euro_fund_rate'),
                EuroFundRateBasis::tryFrom($input->basis) ?? throw new InvalidLifeInsurance('The rate basis is not supported.', 'invalid_euro_fund_rate'),
                $input->sourceNote, $this->clock->now(), $context->actorId,
            );
            if ($this->rates->exists($context->workspace, $contract->id, $instrument->id, $input->year)) {
                throw new LifeInsuranceConflict(LifeInsuranceConflict::RATE_EXISTS);
            }
            if ($this->rates->count($context->workspace, $contract->id) >= self::MAX_PER_CONTRACT) {
                throw new LifeInsuranceConflict(LifeInsuranceConflict::RATE_LIMIT);
            }
            $this->rates->add($rate);
            // A sourced rate references the fixed-price identity just like an operation or valuation.
            $used = $instrument->markUsed($rate->recordedAt);
            if ($used !== $instrument && !$this->instruments->update($used, $instrument->version)) {
                throw new \LogicException('The locked euro-fund instrument could not be marked used.');
            }
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId, LifeInsuranceAuditEvents::RATE_RECORDED,
                LifeInsuranceAuditEvents::RATE, $rate->id, LifeInsuranceAudit::rate($rate),
            ));

            return $rate;
        });
    }
}
