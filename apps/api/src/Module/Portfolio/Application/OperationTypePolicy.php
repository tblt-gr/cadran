<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Application;

use App\Module\Catalog\Domain\AccountKind;
use App\Module\Portfolio\Domain\InvalidPortfolioOperation;
use App\Module\Portfolio\Domain\LifeInsurance\ContractStatus;
use App\Module\Portfolio\Domain\LifeInsurance\LifeInsuranceContract;
use App\Module\Portfolio\Domain\OperationType;

/** Which operations an account kind accepts; the life-insurance tickets add their own types here. */
final class OperationTypePolicy
{
    /** @var list<OperationType> */
    private const array REFUSED_ON_INSURANCE_CONTRACT = [
        OperationType::FEE,
        OperationType::TAX,
        OperationType::INTEREST,
        OperationType::DEPOSIT,
        OperationType::WITHDRAWAL,
        OperationType::SECURITY_TRANSFER_IN,
        OperationType::SECURITY_TRANSFER_OUT,
    ];

    public static function assertAllowed(AccountKind $kind, OperationType $type): void
    {
        if ((AccountKind::INSURANCE_CONTRACT === $kind && in_array($type, self::REFUSED_ON_INSURANCE_CONTRACT, true))
            || (AccountKind::INSURANCE_CONTRACT !== $kind && ($type->hasSupportLegs() || $type->isObservedInsurance()))) {
            throw new InvalidPortfolioOperation('This operation type is not allowed on a life-insurance contract.', 'operation_type_not_allowed_for_account');
        }
    }

    public static function assertContract(AccountKind $kind, ?LifeInsuranceContract $contract, \DateTimeImmutable $tradeDate): void
    {
        if (AccountKind::INSURANCE_CONTRACT !== $kind) {
            return;
        }
        if (null === $contract) {
            throw new InvalidPortfolioOperation('The life-insurance contract must be recorded first.', 'life_insurance_contract_required');
        }
        if (ContractStatus::CLOSED === $contract->status && null !== $contract->closedOn
            && $tradeDate->format('Y-m-d') > $contract->closedOn->format('Y-m-d')) {
            throw new InvalidPortfolioOperation('The life-insurance contract is closed on that date.', 'life_insurance_contract_closed');
        }
    }
}
