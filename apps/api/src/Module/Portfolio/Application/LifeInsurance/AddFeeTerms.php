<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application\LifeInsurance;

use App\Module\Audit\Application\AuditEventRecord;
use App\Module\Audit\Application\RecordAuditEvent;
use App\Module\Foundation\Application\CallerWorkspaceContext;
use App\Module\Foundation\Application\TransactionBoundary;
use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\MalformedDecimal;
use App\Module\Foundation\Domain\PrecisionExceeded;
use App\Module\Foundation\Domain\UuidGenerator;
use App\Module\Portfolio\Application\PortfolioAccountGate;
use App\Module\Portfolio\Domain\LifeInsurance\FeeTerms;
use App\Module\Portfolio\Domain\LifeInsurance\FeeTermsRepository;
use App\Module\Portfolio\Domain\LifeInsurance\InvalidLifeInsurance;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContractRepository;
use App\Module\Reference\Application\AssetCatalog;
use Symfony\Component\Clock\ClockInterface;

/** Records a new dated version of the fee terms; versions are immutable and never produce a fee. */
final readonly class AddFeeTerms
{
    public const int MAX_PER_CONTRACT = 50;

    public function __construct(
        private CallerWorkspaceContext $caller,
        private PortfolioAccountGate $gate,
        private LifeInsuranceContractRepository $contracts,
        private FeeTermsRepository $feeTerms,
        private AssetCatalog $assets,
        private UuidGenerator $uuid,
        private TransactionBoundary $transactions,
        private RecordAuditEvent $audit,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(string $accountId, FeeTermsInput $input): RecordedFeeTerms
    {
        $context = $this->caller->resolveContext();

        return $this->transactions->transactional(function () use ($context, $accountId, $input): RecordedFeeTerms {
            $account = $this->gate->forLifeInsurance($context->workspace, $accountId, true);
            $found = $this->contracts->findByAccount($context->workspace, $account->id) ?? throw new LifeInsuranceNotFound('life_insurance_contract_not_found');
            $contract = $this->contracts->findForUpdate($context->workspace, $found->id) ?? throw new LifeInsuranceNotFound('life_insurance_contract_not_found');

            $validFrom = LifeInsuranceInputParser::day($input->validFrom, 'invalid_fee_terms_date');
            $contract->assertFeeTermsStart($validFrom);
            $terms = new FeeTerms(
                $this->uuid->generate(), $context->workspace, $contract->id, $validFrom,
                LifeInsuranceInputParser::decimal($input->entryFeeRate, 'invalid_fee_rate'),
                LifeInsuranceInputParser::decimal($input->euroFundManagementFeeRate, 'invalid_fee_rate'),
                LifeInsuranceInputParser::decimal($input->unitLinkedManagementFeeRate, 'invalid_fee_rate'),
                LifeInsuranceInputParser::decimal($input->switchFeeRate, 'invalid_fee_rate'),
                $this->fixedFee($input, $account->assetCode),
                $input->sourceNote, $this->clock->now(), $context->actorId,
            );
            if ($this->feeTerms->existsFrom($context->workspace, $contract->id, $validFrom)) {
                throw new LifeInsuranceConflict(LifeInsuranceConflict::FEE_TERMS_EXIST);
            }
            if ($this->feeTerms->count($context->workspace, $contract->id) >= self::MAX_PER_CONTRACT) {
                throw new LifeInsuranceConflict(LifeInsuranceConflict::FEE_TERMS_LIMIT);
            }
            $this->feeTerms->add($terms);
            ($this->audit)(new AuditEventRecord(
                $context->workspace, $context->actorId, LifeInsuranceAuditEvents::FEE_TERMS_ADDED,
                LifeInsuranceAuditEvents::FEE_TERMS, $terms->id, LifeInsuranceAudit::feeTerms($terms),
            ));

            return new RecordedFeeTerms($terms, $account->assetCode->toString());
        });
    }

    private function fixedFee(FeeTermsInput $input, AssetCode $accountAsset): ?DecimalValue
    {
        if (null === $input->switchFeeFixed) {
            return null;
        }
        $asset = $this->assets->findByCode($accountAsset) ?? throw new \LogicException('The account asset is missing from the reference.');
        if ($input->switchFeeFixed->assetCode !== $asset->code->toString()) {
            throw new InvalidLifeInsurance('The fixed fee is in the account currency.', 'invalid_fee_rate');
        }
        try {
            return $asset->amount($input->switchFeeFixed->value)->value;
        } catch (MalformedDecimal|PrecisionExceeded) {
            throw new InvalidLifeInsurance('The fixed fee is a canonical decimal within the currency precision.', 'invalid_fee_rate');
        }
    }
}
