<?php

declare(strict_types=1);

namespace App\Module\Portfolio\Domain;

use App\Module\Foundation\Domain\AssetCode;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * A recorded portfolio operation. It is immutable: the only change a stored
 * operation ever receives is its void marker, so history stays reproducible.
 */
final readonly class PortfolioOperation
{
    public const int MAX_ACTIVE_PER_ACCOUNT = 10_000;

    public ?string $voidReason;

    public function __construct(
        public string $id,
        public WorkspaceScope $workspace,
        public string $accountId,
        public AssetCode $assetCode,
        public OperationDetails $details,
        public int $sequence,
        public DecimalValue $cashAmount,
        public ?DecimalValue $roundingResidual,
        public OperationState $state,
        public ?\DateTimeImmutable $voidedAt,
        public ?string $voidedBy,
        ?string $voidReason,
        public ?OperationVoidKind $voidKind,
        public ?string $correctsOperationId,
        public \DateTimeImmutable $createdAt,
        public string $createdBy,
        public int $version,
    ) {
        if ($sequence < 1 || $version < 1) {
            throw new InvalidPortfolioOperation('An operation sequence and version are positive.', 'invalid_operation_version');
        }
        $voided = OperationState::VOIDED === $state;
        if ($voided !== (null !== $voidedAt && null !== $voidedBy && null !== $voidKind)
            || (!$voided && (null !== $voidedAt || null !== $voidedBy || null !== $voidKind || null !== $voidReason))) {
            throw new InvalidPortfolioOperation('Void markers appear together, and only on a voided operation.', 'invalid_operation_version');
        }
        $this->voidReason = null === $voidReason ? null : OperationText::normalize($voidReason, OperationText::MAX_REASON);
    }

    public function isActive(): bool
    {
        return OperationState::ACTIVE === $this->state;
    }

    public function assertActive(): void
    {
        if (!$this->isActive()) {
            throw new InvalidPortfolioOperation('The operation is already voided or replaced.', 'operation_voided');
        }
    }

    public function void(OperationVoidKind $kind, ?string $reason, \DateTimeImmutable $at, string $actorId): self
    {
        $this->assertActive();
        if (OperationVoidKind::VOIDED === $kind && null === $reason) {
            throw new InvalidPortfolioOperation('Voiding an operation requires a reason.', 'invalid_operation_text');
        }

        return new self(
            $this->id, $this->workspace, $this->accountId, $this->assetCode, $this->details, $this->sequence,
            $this->cashAmount, $this->roundingResidual, OperationState::VOIDED, $at, $actorId, $reason, $kind,
            $this->correctsOperationId, $this->createdAt, $this->createdBy, $this->version + 1,
        );
    }
}
