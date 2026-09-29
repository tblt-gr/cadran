<?php

declare(strict_types=1);

namespace App\Module\Budget\Domain\EmergencyFund;

use App\Module\Accounts\Domain\CalendarMonth;
use App\Module\Foundation\Domain\DecimalValue;
use App\Module\Foundation\Domain\WorkspaceScope;

/**
 * The single emergency-fund policy of a workspace: which expenses are essential, over which
 * reference months they are averaged, and how many months of them the owner wants covered.
 * Eligible accounts are not part of it: the account flag is their only source.
 */
final readonly class EmergencyFundPolicy
{
    /** Days between a valuation and the as-of date beyond which the valuation is flagged stale. */
    public const int STALE_VALUATION_DAYS = 31;
    public const int MAX_CATEGORIES = 50;
    public const int MAX_TRAILING_MONTHS = 24;
    public const string MAX_TARGET_MONTHS = '60';
    public const int TARGET_SCALE = 1;
    public const int UNSAVED_VERSION = 0;
    private const string DEFAULT_TARGET_MONTHS = '6';

    /** @param list<string> $essentialCategoryIds */
    public function __construct(
        public WorkspaceScope $workspace,
        public EssentialMode $essentialMode,
        public array $essentialCategoryIds,
        public ReferenceMode $referenceMode,
        public ?int $trailingMonths,
        public DecimalValue $targetMonths,
        public int $version,
        public ?\DateTimeImmutable $updatedAt = null,
        public ?string $updatedBy = null,
    ) {
        if (EssentialMode::AXIS === $essentialMode && [] !== $essentialCategoryIds) {
            throw new InvalidEmergencyFundPolicy('invalid_emergency_fund_policy', 'The essential axis takes no category.');
        }
        if (EssentialMode::CATEGORIES === $essentialMode && [] === $essentialCategoryIds) {
            throw new InvalidEmergencyFundPolicy('invalid_emergency_fund_policy', 'An explicit essential perimeter names at least one category.');
        }
        if (count($essentialCategoryIds) > self::MAX_CATEGORIES) {
            throw new InvalidEmergencyFundPolicy('too_many_emergency_fund_categories', 'An essential perimeter names at most 50 categories.');
        }
        if (count($essentialCategoryIds) !== count(array_unique($essentialCategoryIds))) {
            throw new InvalidEmergencyFundPolicy('invalid_emergency_fund_policy', 'An essential category appears once.');
        }
        if (ReferenceMode::PREVIOUS_YEAR === $referenceMode && null !== $trailingMonths) {
            throw new InvalidEmergencyFundPolicy('invalid_trailing_months', 'The previous-year reference takes no month count.');
        }
        if (ReferenceMode::TRAILING_MONTHS === $referenceMode && (null === $trailingMonths || $trailingMonths < 1 || $trailingMonths > self::MAX_TRAILING_MONTHS)) {
            throw new InvalidEmergencyFundPolicy('invalid_trailing_months', 'A trailing reference reads 1 to 24 complete months.');
        }
        if ($targetMonths->isNegative() || $targetMonths->scale() > self::TARGET_SCALE || $targetMonths->compareTo(DecimalValue::fromString(self::MAX_TARGET_MONTHS)) > 0) {
            throw new InvalidEmergencyFundPolicy('invalid_target_months', 'A target is 0 to 60 months with at most one decimal.');
        }
        if ($version < self::UNSAVED_VERSION) {
            throw new \InvalidArgumentException('A policy version is never negative.');
        }
    }

    public static function default(WorkspaceScope $workspace): self
    {
        return new self($workspace, EssentialMode::AXIS, [], ReferenceMode::PREVIOUS_YEAR, null, DecimalValue::fromString(self::DEFAULT_TARGET_MONTHS), self::UNSAVED_VERSION);
    }

    /** @param list<string> $essentialCategoryIds */
    public function revise(
        EssentialMode $essentialMode,
        array $essentialCategoryIds,
        ReferenceMode $referenceMode,
        ?int $trailingMonths,
        DecimalValue $targetMonths,
        \DateTimeImmutable $updatedAt,
        string $updatedBy,
    ): self {
        return new self($this->workspace, $essentialMode, $essentialCategoryIds, $referenceMode, $trailingMonths, $targetMonths, $this->version + 1, $updatedAt, $updatedBy);
    }

    /**
     * The names of the fields that differ in the other policy, for an audit trail that must never
     * carry the figures themselves.
     *
     * @return list<string>
     */
    public function changedFields(self $other): array
    {
        $current = $this->essentialCategoryIds;
        $next = $other->essentialCategoryIds;
        sort($current);
        sort($next);

        return array_keys(array_filter([
            'essentialMode' => $this->essentialMode !== $other->essentialMode,
            'essentialCategoryIds' => $current !== $next,
            'referenceMode' => $this->referenceMode !== $other->referenceMode,
            'trailingMonths' => $this->trailingMonths !== $other->trailingMonths,
            'targetMonths' => 0 !== $this->targetMonths->compareTo($other->targetMonths),
        ]));
    }

    /** The complete months the average reads for an as-of date in the given month. */
    public function referencePeriod(CalendarMonth $asOfMonth): ReferencePeriod
    {
        if (ReferenceMode::PREVIOUS_YEAR === $this->referenceMode) {
            return new ReferencePeriod(new CalendarMonth($asOfMonth->year - 1, 1), new CalendarMonth($asOfMonth->year - 1, 12), 12);
        }
        $count = $this->trailingMonths ?? throw new \LogicException('A trailing reference carries its month count.');

        return new ReferencePeriod(
            CalendarMonth::containing($asOfMonth->firstDay()->modify(sprintf('-%d months', $count))),
            CalendarMonth::containing($asOfMonth->firstDay()->modify('-1 month')),
            $count,
        );
    }
}
