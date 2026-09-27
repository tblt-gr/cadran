<?php

declare(strict_types=1);

namespace App\Module\Reporting\Application;

use App\Module\Accounts\Application\ReportAccountReference;
use App\Module\Categories\Domain\AnalyticAxis;
use App\Module\Reporting\Domain\Aggregation\ColumnKind;

/**
 * The columns one workspace may put in its annual report: the fixed indicators, the analytic axes
 * and the categories, groups and accounts the workspace itself owns, archived or not. An id that
 * names anything else is not in the catalogue, so a foreign id can never select a figure.
 */
final readonly class AnnualCatalogue
{
    public const string UNKNOWN_LABEL = 'Référence inconnue';
    public const int DEFAULT_ACCOUNT_COLUMNS = 3;

    private const array FIXED = [
        'cashIncome' => [ColumnKind::FLOW, 'Revenus de trésorerie'],
        'nonCashBenefits' => [ColumnKind::FLOW, 'Avantages hors trésorerie'],
        'budgetExpenses' => [ColumnKind::FLOW, 'Dépenses budgétaires'],
        'benefitSpending' => [ColumnKind::FLOW, 'Dépenses en avantages'],
        'uncategorizedExpenses' => [ColumnKind::FLOW, 'Dépenses non catégorisées'],
        'budgetSurplus' => [ColumnKind::FLOW, 'Excédent budgétaire'],
        'cashSavingsRate' => [ColumnKind::RATE, 'Taux d’épargne de trésorerie'],
        'savingsInflows' => [ColumnKind::FLOW, 'Entrées d’épargne'],
        'savingsWithdrawals' => [ColumnKind::FLOW, 'Sorties d’épargne'],
        'netSavingsTransfers' => [ColumnKind::FLOW, 'Épargne nette'],
        'netSavingsRate' => [ColumnKind::RATE, 'Taux d’épargne nette'],
        'netWorthDelta' => [ColumnKind::FLOW, 'Variation du patrimoine net'],
        'endNetWorth' => [ColumnKind::STOCK, 'Patrimoine net en fin de mois'],
    ];

    private const array AXIS_LABELS = [
        'DISCRETIONARY' => 'Discrétionnaire',
        'ESSENTIAL' => 'Essentiel',
        'FIXED' => 'Fixe',
        'PERSONAL' => 'Personnel',
        'PROFESSIONAL' => 'Professionnel',
        'VARIABLE' => 'Variable',
    ];

    /**
     * @param array<string, string>                 $categories label by category id
     * @param array<string, string>                 $groups     label by group id
     * @param array<string, ReportAccountReference> $accounts   by account id
     */
    public function __construct(
        private array $categories,
        private array $groups,
        private array $accounts,
    ) {
    }

    /** True for a column that resolves in this workspace. */
    public function has(string $id): bool
    {
        $column = $this->column($id);

        return null !== $column && $column->known;
    }

    /** Null for an id that is not shaped like any column; an unresolved reference comes back unknown. */
    public function column(string $id): ?AnnualColumn
    {
        if (isset(self::FIXED[$id])) {
            return new AnnualColumn($id, self::FIXED[$id][1], self::FIXED[$id][0], true);
        }
        [$type, $reference] = array_pad(explode(':', $id, 2), 2, '');
        $known = static fn (?string $label): AnnualColumn => new AnnualColumn($id, $label ?? self::UNKNOWN_LABEL, ColumnKind::FLOW, null !== $label);

        return match ($type) {
            'axis' => isset(self::AXIS_LABELS[$reference])
                ? new AnnualColumn($id, 'Dépenses : '.self::AXIS_LABELS[$reference], ColumnKind::FLOW, true)
                : null,
            'category' => '' === $reference ? null : $known($this->categories[$reference] ?? null),
            'group' => '' === $reference ? null : self::stock($id, $this->groups[$reference] ?? null),
            'account' => '' === $reference ? null : self::stock($id, isset($this->accounts[$reference]) ? $this->accounts[$reference]->label : null),
            default => null,
        };
    }

    public function categoryLabel(string $id): ?string
    {
        return $this->categories[$id] ?? null;
    }

    public function groupLabel(string $id): ?string
    {
        return $this->groups[$id] ?? null;
    }

    /** @return list<string> */
    public function defaultSelection(): array
    {
        $current = array_values(array_filter(
            $this->accounts,
            static fn (ReportAccountReference $account): bool => 'CURRENT' === $account->kind && $account->includeInNetWorth && !$account->archived,
        ));
        usort($current, static fn (ReportAccountReference $a, ReportAccountReference $b): int => [$a->label, $a->id] <=> [$b->label, $b->id]);

        return [
            'cashIncome', 'budgetExpenses', 'budgetSurplus', 'cashSavingsRate', 'netSavingsTransfers', 'netSavingsRate', 'endNetWorth',
            ...array_map(
                static fn (ReportAccountReference $account): string => 'account:'.$account->id,
                array_slice($current, 0, self::DEFAULT_ACCOUNT_COLUMNS),
            ),
        ];
    }

    /** @return list<string> */
    public static function axes(): array
    {
        return array_map(static fn (AnalyticAxis $axis): string => $axis->value, AnalyticAxis::cases());
    }

    private static function stock(string $id, ?string $label): AnnualColumn
    {
        return new AnnualColumn($id, $label ?? self::UNKNOWN_LABEL, ColumnKind::STOCK, null !== $label);
    }
}
