<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

/**
 * Every figure of one month an annual column can read, keyed by column id: what the annual report
 * takes from a live projection or from the snapshot of a closed month. It holds no label, since
 * labels are read live, and no formula.
 */
final readonly class MonthFigures
{
    /** Bumped whenever the payload layout changes; a snapshot of another version is recaptured. */
    public const int SCHEMA_VERSION = 1;

    /** Column ids read straight from a KPI of the projection. */
    public const array KPI_IDS = [
        'cashIncome', 'nonCashBenefits', 'budgetExpenses', 'benefitSpending', 'uncategorizedExpenses',
        'budgetSurplus', 'cashSavingsRate', 'savingsInflows', 'savingsWithdrawals', 'netSavingsTransfers',
        'netSavingsRate', 'netWorthDelta', 'endNetWorth',
    ];

    /**
     * @param array<string, MonthlyMetricView> $cells
     * @param list<string>                     $budgetExpenseCategoryIds
     * @param list<AllocationFigure>           $allocation
     */
    public function __construct(
        public string $month,
        public int $policyVersion,
        public string $quality,
        public int $pendingCount,
        public string $netWorthAsOf,
        public array $cells,
        public array $budgetExpenseCategoryIds,
        public array $allocation,
    ) {
    }

    public static function fromProjection(MonthlyProjectionView $projection): self
    {
        $cells = [];
        foreach (self::KPI_IDS as $id) {
            $cells[$id] = $projection->{$id};
        }
        foreach ($projection->expensesByAxis as $axis => $metric) {
            $cells['axis:'.$axis] = $metric;
        }
        foreach ($projection->categoryMetrics as $category) {
            $cells['category:'.$category->id] = $category->metric;
        }
        foreach ($projection->accounts as $account) {
            $end = $account->endValue;
            $cells['account:'.$account->id] = new MonthlyMetricView(
                $end->value,
                $end->assetCode,
                null === $end->value ? 'MISSING_VALUATION' : null,
            );
        }
        $allocation = [];
        foreach ($projection->netWorth->allocation as $item) {
            $cells['group:'.$item->groupId] = new MonthlyMetricView(
                $item->value?->amount,
                $item->value?->asset,
                null === $item->value ? ($projection->netWorth->reason ?? 'MISSING_VALUATION') : null,
            );
            $allocation[] = new AllocationFigure(
                $item->groupId,
                $item->value?->amount,
                $item->value?->asset,
                $item->share->ratio,
                $item->share->reason,
            );
        }

        return new self(
            $projection->month,
            $projection->metricPolicy->version ?? 1,
            $projection->quality,
            $projection->pendingCount,
            $projection->netWorthAsOf,
            $cells,
            $projection->budgetExpenseCategoryIds,
            $allocation,
        );
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'month' => $this->month,
            'policyVersion' => $this->policyVersion,
            'quality' => $this->quality,
            'pendingCount' => $this->pendingCount,
            'netWorthAsOf' => $this->netWorthAsOf,
            'cells' => array_map(static fn (MonthlyMetricView $metric): array => [
                'value' => $metric->value,
                'assetCode' => $metric->assetCode,
                'reason' => $metric->reason,
                'sourceTransactionIds' => $metric->sourceTransactionIds,
                'sourceAccountIds' => $metric->sourceAccountIds,
                'pendingCount' => $metric->pendingCount,
                'sourceTransferIds' => $metric->sourceTransferIds,
            ], $this->cells),
            'budgetExpenseCategoryIds' => $this->budgetExpenseCategoryIds,
            'allocation' => array_map(static fn (AllocationFigure $item): array => [
                'groupId' => $item->groupId,
                'value' => $item->value,
                'asset' => $item->asset,
                'share' => $item->share,
                'reason' => $item->reason,
            ], $this->allocation),
        ];
    }

    /**
     * @param array<mixed> $payload
     *
     * @throws \UnexpectedValueException when the payload is not one this schema wrote
     */
    public static function fromPayload(array $payload): self
    {
        $cells = [];
        foreach (self::list($payload['cells'] ?? null) as $id => $cell) {
            $cell = self::list($cell);
            $cells[(string) $id] = new MonthlyMetricView(
                self::nullableString($cell['value'] ?? null),
                self::nullableString($cell['assetCode'] ?? null),
                self::nullableString($cell['reason'] ?? null),
                self::strings($cell['sourceTransactionIds'] ?? null),
                self::strings($cell['sourceAccountIds'] ?? null),
                self::integer($cell['pendingCount'] ?? null),
                self::strings($cell['sourceTransferIds'] ?? null),
            );
        }
        $allocation = [];
        foreach (self::list($payload['allocation'] ?? null) as $item) {
            $item = self::list($item);
            $groupId = $item['groupId'] ?? null;
            if (!is_string($groupId)) {
                throw new \UnexpectedValueException('A snapshot allocation names its group.');
            }
            $allocation[] = new AllocationFigure(
                $groupId,
                self::nullableString($item['value'] ?? null),
                self::nullableString($item['asset'] ?? null),
                self::nullableString($item['share'] ?? null),
                self::nullableString($item['reason'] ?? null),
            );
        }
        $month = $payload['month'] ?? null;
        $quality = $payload['quality'] ?? null;
        $asOf = $payload['netWorthAsOf'] ?? null;
        if (!is_string($month) || !is_string($quality) || !is_string($asOf)) {
            throw new \UnexpectedValueException('A snapshot payload names its month, quality and valuation day.');
        }

        return new self(
            $month,
            self::integer($payload['policyVersion'] ?? null),
            $quality,
            self::integer($payload['pendingCount'] ?? null),
            $asOf,
            $cells,
            self::strings($payload['budgetExpenseCategoryIds'] ?? null),
            $allocation,
        );
    }

    /** @return array<mixed> */
    private static function list(mixed $value): array
    {
        if (!is_array($value)) {
            throw new \UnexpectedValueException('A snapshot payload member is a collection.');
        }

        return $value;
    }

    /** @return list<string> */
    private static function strings(mixed $value): array
    {
        $items = [];
        foreach (self::list($value) as $item) {
            if (!is_string($item)) {
                throw new \UnexpectedValueException('A snapshot payload list holds strings.');
            }
            $items[] = $item;
        }

        return $items;
    }

    private static function nullableString(mixed $value): ?string
    {
        if (null !== $value && !is_string($value)) {
            throw new \UnexpectedValueException('A snapshot payload figure is text or null.');
        }

        return $value;
    }

    private static function integer(mixed $value): int
    {
        if (!is_int($value)) {
            throw new \UnexpectedValueException('A snapshot payload count is an integer.');
        }

        return $value;
    }
}
