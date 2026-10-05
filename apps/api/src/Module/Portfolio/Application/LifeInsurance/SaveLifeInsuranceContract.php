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
use App\Module\Portfolio\Domain\LifeInsurance\ContractNumberSuffix;
use App\Module\Portfolio\Domain\LifeInsurance\EuroFundRateRepository;
use App\Module\Portfolio\Domain\LifeInsurance\FeeTermsRepository;
use App\Module\Portfolio\Domain\LifeInsurance\InvalidLifeInsurance;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContract;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContractRepository;
use App\Module\Portfolio\Domain\LifeInsurance\ManagementMode;
use Symfony\Component\Clock\ClockInterface;

/** Creates the one contract of a life-insurance account, or revises it under an optimistic version. */
final readonly class SaveLifeInsuranceContract
{
    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private LifeInsuranceContractRepository $contracts,
        private FeeTermsRepository $feeTerms,
        private EuroFundRateRepository $rates,
        private UuidGenerator $uuid,
        private TransactionBoundary $transactions,
        private RecordAuditEvent $audit,
        private WorkspaceCalendar $calendar,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $accountId, LifeInsuranceContractInput $input): SavedLifeInsuranceContract
    {
        $context = $this->caller->resolveContext();
        // The number is reduced to its suffix here and never travels further.
        $suffix = null === $input->contractNumber ? null : ContractNumberSuffix::fromNumber($input->contractNumber);
        $mode = ManagementMode::tryFrom($input->managementMode) ?? throw new InvalidLifeInsurance('The management mode is not supported.', 'invalid_contract_field');
        $subscribedOn = LifeInsuranceInputParser::day($input->subscribedOn, 'invalid_contract_date');
        $taxStartOn = null === $input->taxStartOn ? $subscribedOn : LifeInsuranceInputParser::day($input->taxStartOn, 'invalid_contract_date');

        return $this->transactions->transactional(function () use ($context, $accountId, $input, $suffix, $mode, $subscribedOn, $taxStartOn): SavedLifeInsuranceContract {
            $account = $this->gate->forLifeInsurance($context->workspace, $accountId, true);
            $existing = $this->contracts->findByAccount($context->workspace, $account->id);
            $now = $this->clock->now();
            $today = $this->calendar->today();

            if (null === $existing) {
                if (null !== $input->version) {
                    throw new LifeInsuranceConflict(LifeInsuranceConflict::STALE_VERSION);
                }
                $contract = LifeInsuranceContract::open(
                    $this->uuid->generate(), $context->workspace, $account->id, $input->insurer, $input->distributor,
                    $input->commercialName, $suffix, $subscribedOn, $taxStartOn, $mode, $now, $context->actorId, $today,
                );
                $this->contracts->add($contract);
                ($this->audit)(new AuditEventRecord(
                    $context->workspace, $context->actorId, LifeInsuranceAuditEvents::CONTRACT_CREATED,
                    LifeInsuranceAuditEvents::CONTRACT, $contract->id, LifeInsuranceAudit::contractCreated($contract),
                ));

                return new SavedLifeInsuranceContract($contract, true);
            }
            // Share the child writers' lock before validating their dates against a revision.
            $existing = $this->contracts->findForUpdate($context->workspace, $existing->id)
                ?? throw new LifeInsuranceNotFound('life_insurance_contract_not_found');
            if (null === $input->version) {
                throw new LifeInsuranceConflict(LifeInsuranceConflict::CONTRACT_EXISTS);
            }
            if ($input->version !== $existing->version) {
                throw new LifeInsuranceConflict(LifeInsuranceConflict::STALE_VERSION);
            }
            $revised = $existing->revise(
                $input->insurer, $input->distributor, $input->commercialName,
                $input->contractNumberProvided ? $suffix : $existing->numberSuffix,
                $subscribedOn, $taxStartOn, $mode, $now, $today,
            );
            foreach ($this->feeTerms->listForContract($context->workspace, $existing->id) as $terms) {
                $revised->assertFeeTermsStart($terms->validFrom);
            }
            foreach ($this->rates->listForContract($context->workspace, $existing->id) as $rate) {
                $revised->assertRateYear($rate->year, (int) $today->format('Y'));
            }
            if (!$this->contracts->update($revised, $existing->version)) {
                throw new LifeInsuranceConflict(LifeInsuranceConflict::STALE_VERSION);
            }
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId, LifeInsuranceAuditEvents::CONTRACT_UPDATED,
                LifeInsuranceAuditEvents::CONTRACT, $revised->id, LifeInsuranceAudit::contractUpdated($revised->changedFields($existing), $revised->version),
            ));

            return new SavedLifeInsuranceContract($revised, false);
        });
    }
}
