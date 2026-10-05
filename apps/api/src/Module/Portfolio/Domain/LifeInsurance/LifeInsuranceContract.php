<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain\LifeInsurance;

use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The record of one life-insurance contract: a multi-support wrapper whose value is the sum of its supports,
 * valued by the portfolio module. It holds only descriptive, dated facts and the last four characters of
 * the contract number.
 */
final readonly class LifeInsuranceContract
{
    public const int MAX_INSURER_LENGTH = 80;
    public const int MAX_DISTRIBUTOR_LENGTH = 80;
    public const int MAX_COMMERCIAL_NAME_LENGTH = 120;

    public string $insurer;
    public ?string $distributor;
    public ?string $commercialName;

    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $accountId,
        string $insurer,
        ?string $distributor,
        ?string $commercialName,
        public ?ContractNumberSuffix $numberSuffix,
        public \DateTimeImmutable $subscribedOn,
        public \DateTimeImmutable $taxStartOn,
        public ManagementMode $managementMode,
        public ContractStatus $status,
        public ?\DateTimeImmutable $closedOn,
        public \DateTimeImmutable $createdAt,
        public string $createdBy,
        public \DateTimeImmutable $updatedAt,
        public int $version,
    ) {
        if ((ContractStatus::CLOSED === $status) !== (null !== $closedOn)) {
            throw new InvalidLifeInsurance('A contract has a closing date exactly when it is closed.', 'invalid_contract_date');
        }
        if ($version < 1) {
            throw new InvalidLifeInsurance('A contract version is positive.', 'invalid_contract_field');
        }
        $this->insurer = ContractText::required($insurer, self::MAX_INSURER_LENGTH);
        $this->distributor = ContractText::optional($distributor, self::MAX_DISTRIBUTOR_LENGTH);
        $this->commercialName = ContractText::optional($commercialName, self::MAX_COMMERCIAL_NAME_LENGTH);
    }

    public static function open(
        string $id,
        WorkspaceScope $workspace,
        string $accountId,
        string $insurer,
        ?string $distributor,
        ?string $commercialName,
        ?ContractNumberSuffix $numberSuffix,
        \DateTimeImmutable $subscribedOn,
        ?\DateTimeImmutable $taxStartOn,
        ManagementMode $managementMode,
        \DateTimeImmutable $at,
        string $actorId,
        \DateTimeImmutable $today,
    ): self {
        $taxStart = $taxStartOn ?? $subscribedOn;
        self::assertDates($subscribedOn, $taxStart, $today);

        return new self(
            $id, $workspace, $accountId, $insurer, $distributor, $commercialName, $numberSuffix, $subscribedOn,
            $taxStart, $managementMode, ContractStatus::OPEN, null, $at, $actorId, $at, 1,
        );
    }

    public function revise(
        string $insurer,
        ?string $distributor,
        ?string $commercialName,
        ?ContractNumberSuffix $numberSuffix,
        \DateTimeImmutable $subscribedOn,
        \DateTimeImmutable $taxStartOn,
        ManagementMode $managementMode,
        \DateTimeImmutable $at,
        \DateTimeImmutable $today,
    ): self {
        self::assertDates($subscribedOn, $taxStartOn, $today);

        return new self(
            $this->id, $this->workspace, $this->accountId, $insurer, $distributor, $commercialName, $numberSuffix,
            $subscribedOn, $taxStartOn, $managementMode, $this->status, $this->closedOn, $this->createdAt,
            $this->createdBy, $at, $this->version + 1,
        );
    }

    /**
     * Names only: the values of a contract never enter the audit trail.
     *
     * @return list<string>
     */
    public function changedFields(self $before): array
    {
        $changed = [];
        foreach ([
            'insurer' => $this->insurer !== $before->insurer,
            'distributor' => $this->distributor !== $before->distributor,
            'commercialName' => $this->commercialName !== $before->commercialName,
            'contractNumber' => $this->numberSuffix?->value !== $before->numberSuffix?->value,
            'subscribedOn' => $this->subscribedOn->format('Y-m-d') !== $before->subscribedOn->format('Y-m-d'),
            'taxStartOn' => $this->taxStartOn->format('Y-m-d') !== $before->taxStartOn->format('Y-m-d'),
            'managementMode' => $this->managementMode !== $before->managementMode,
        ] as $field => $differs) {
            if ($differs) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    public function assertFeeTermsStart(\DateTimeImmutable $validFrom): void
    {
        if ($validFrom->format('Y-m-d') < $this->subscribedOn->format('Y-m-d')) {
            throw new InvalidLifeInsurance('Fee terms cannot start before the subscription.', 'invalid_fee_terms_date');
        }
    }

    public function assertRateYear(int $year, int $currentYear): void
    {
        if ($year < (int) $this->subscribedOn->format('Y') || $year > $currentYear) {
            throw new InvalidLifeInsurance('A euro-fund rate year lies between the subscription year and today.', 'invalid_euro_fund_rate');
        }
    }

    private static function assertDates(\DateTimeImmutable $subscribedOn, \DateTimeImmutable $taxStartOn, \DateTimeImmutable $today): void
    {
        $limit = $today->format('Y-m-d');
        if ($subscribedOn->format('Y-m-d') > $limit || $taxStartOn->format('Y-m-d') > $limit) {
            throw new InvalidLifeInsurance('A contract date cannot lie in the future.', 'invalid_contract_date');
        }
    }
}
