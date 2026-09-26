<?php

declare(strict_types=1);

namespace App\Module\Reporting\Domain;

use App\Module\Catalog\Domain\AccountKind;

/**
 * One immutable definition of what counts as cash income and budget expenses.
 * Version 1 is built in and has no row; workspace versions start at 2.
 */
final readonly class MetricPolicy
{
    public const int SYSTEM_VERSION = 1;
    public const int MAX_LABEL_LENGTH = 80;
    public const int MAX_EXCLUDED_KINDS = 8;

    /** @param list<AccountKind> $cashExcludedAccountKinds sorted, unique */
    private function __construct(
        public int $version,
        public string $label,
        public array $cashExcludedAccountKinds,
        public SavingsRateFormula $savingsRateFormula,
        public NetSavingsRateFormula $netSavingsRateFormula,
        public ?\DateTimeImmutable $createdAt,
        public ?string $createdBy,
    ) {
    }

    public static function systemV1(): self
    {
        return new self(
            self::SYSTEM_VERSION,
            'Définition de trésorerie',
            [AccountKind::EMPLOYEE_BENEFIT],
            SavingsRateFormula::BUDGET_SURPLUS_OVER_CASH_INCOME,
            NetSavingsRateFormula::NET_SAVINGS_TRANSFERS_OVER_CASH_INCOME,
            null,
            null,
        );
    }

    /** @param list<AccountKind> $excludedKinds */
    public static function create(int $version, string $label, array $excludedKinds, \DateTimeImmutable $createdAt, string $createdBy): self
    {
        return self::rehydrate(
            $version,
            $label,
            $excludedKinds,
            SavingsRateFormula::BUDGET_SURPLUS_OVER_CASH_INCOME,
            NetSavingsRateFormula::NET_SAVINGS_TRANSFERS_OVER_CASH_INCOME,
            $createdAt,
            $createdBy,
        );
    }

    /** @param list<AccountKind> $excludedKinds */
    public static function rehydrate(
        int $version,
        string $label,
        array $excludedKinds,
        SavingsRateFormula $savingsRateFormula,
        NetSavingsRateFormula $netSavingsRateFormula,
        \DateTimeImmutable $createdAt,
        string $createdBy,
    ): self {
        if ($version < self::SYSTEM_VERSION + 1) {
            throw new InvalidMetricPolicy('A workspace metric policy version starts at 2.');
        }
        $label = trim($label);
        $length = mb_strlen($label);
        if ($length < 1 || $length > self::MAX_LABEL_LENGTH) {
            throw new InvalidMetricPolicy('A metric policy label holds 1 to 80 characters.');
        }
        if (count($excludedKinds) > self::MAX_EXCLUDED_KINDS) {
            throw new InvalidMetricPolicy('A metric policy excludes at most 8 account kinds.');
        }
        $values = array_map(static fn (AccountKind $kind): string => $kind->value, $excludedKinds);
        if (count(array_unique($values)) !== count($values)) {
            throw new InvalidMetricPolicy('A metric policy lists each excluded account kind once.');
        }
        sort($values, SORT_STRING);

        return new self(
            $version,
            $label,
            array_map(static fn (string $value): AccountKind => AccountKind::from($value), $values),
            $savingsRateFormula,
            $netSavingsRateFormula,
            $createdAt,
            $createdBy,
        );
    }

    public function isExcluded(?string $accountKind): bool
    {
        if (null === $accountKind) {
            return false;
        }
        foreach ($this->cashExcludedAccountKinds as $kind) {
            if ($kind->value === $accountKind) {
                return true;
            }
        }

        return false;
    }

    /** Whether another policy excludes exactly the same account kinds. */
    public function sameDefinitionAs(self $other): bool
    {
        return $this->cashExcludedAccountKinds === $other->cashExcludedAccountKinds;
    }
}
