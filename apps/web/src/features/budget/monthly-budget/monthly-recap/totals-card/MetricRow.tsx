import type {
  Category,
  GoalEvaluation,
  MonthlyRecapAxis,
  MonthlyRecapCategory,
} from '@cadran/api-client';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import {
  expenseTransactionsHref,
  monthBounds,
} from '@/features/budget/monthly-budget/expense-donut/expenseTransactionsHref';
import { CategoryGlyph } from '@/features/categories/category-glyph/CategoryGlyph';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { GoalMarkedValue, GoalTarget } from '@/features/goals/goal-mark/GoalMark';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatAmount } from '@/lib/decimal';
import { MetricExplanationModal } from './MetricExplanationModal';
import { MetricLabelButton } from './MetricLabelButton';
import { MetricValue } from './MetricValue';
import type { RecapMetric } from './recapMetric';
import styles from './TotalsCard.module.css';

export function MetricRow({
  axis,
  label: suppliedLabel,
  month,
  metric,
  marks = [],
  goalPeriod,
  goalColumn = false,
  rate = false,
  benefit = null,
  onCreateGoal,
  lead,
  href = null,
}: {
  /** Opens the filtered transactions instead of the calculation explanation. */
  href?: string | null;
  /** Glyph drawn before the label, such as a category's icon. */
  lead?: ReactNode;
  /** Opens the goal form on this row's figure; absent when no goal can target it. */
  onCreateGoal?: () => void;
  /** The goals of the displayed month that carry this row's figure. */
  marks?: readonly GoalEvaluation[];
  goalPeriod?: string;
  /** Whether the card has its `Objectif` column. */
  goalColumn?: boolean;
  /** Spending outside the treasury perimeter (meal vouchers...), told apart from the total. */
  benefit?: { assetCode: string; value: string } | null;
  axis?: MonthlyRecapAxis['axis'];
  label?: string;
  month: string;
  metric: RecapMetric | null;
  rate?: boolean;
}) {
  const { i18n, t } = useTranslation();
  const [open, setOpen] = useState(false);
  const label =
    suppliedLabel ??
    (axis
      ? t(`budget.monthly.axes.${axis}`)
      : metric?.kpi
        ? t(`reports.metrics.${metric.kpi}`)
        : t('states.notCalculable.label'));
  const labelNode =
    href !== null ? (
      <a
        aria-label={t('budget.monthly.expenseDonut.showTransactions', { label })}
        className={styles.label}
        href={href}
        onClick={(event) => handleClientNavigation(event, href)}
      >
        {label}
      </a>
    ) : marks.length > 0 ? (
      // A goal's `i` replaces the calculation explanation of the row.
      <span className={styles.labelText}>{label}</span>
    ) : (
      <MetricLabelButton label={label} onClick={() => setOpen(true)} />
    );
  return (
    <li className={styles.row}>
      <div className={goalColumn ? styles.withGoals : undefined}>
        {lead ? (
          <span className={styles.labelCell}>
            {lead}
            {labelNode}
          </span>
        ) : (
          labelNode
        )}
        {metric === null ? (
          <SkeletonBlock className={styles.amountSkeleton} />
        ) : (
          <GoalMarkedValue items={marks}>
            <MetricValue metric={metric} rate={rate} />
          </GoalMarkedValue>
        )}
        {goalColumn ? (
          <span className={styles.goal}>
            {goalPeriod ? (
              <GoalTarget items={marks} label={label} onCreate={onCreateGoal} period={goalPeriod} />
            ) : null}
          </span>
        ) : null}
      </div>
      {benefit ? (
        <p className={styles.benefit}>
          {t('budget.monthly.recap.totals.benefitSpending')}{' '}
          <MoneyValue value={formatAmount(benefit.value, benefit.assetCode, i18n.language)} />
        </p>
      ) : null}
      {open && metric ? (
        <MetricExplanationModal
          close={() => setOpen(false)}
          kpi={metric.kpi}
          label={label}
          month={month}
          sourceTransactions={metric.sourceTransactions}
        />
      ) : null}
    </li>
  );
}

export function CategoryRow({
  category,
  marks,
  goalColumn,
  month,
  goalPeriod,
  onCreateGoal,
  identity,
  parent = true,
}: {
  /** The workspace category behind the row, for its icon and colour. */
  identity?: Category;
  /** Whether the category has sub-categories, its figure then covering them; true while unknown. */
  parent?: boolean;
  onCreateGoal?: () => void;
  category: MonthlyRecapCategory;
  goalColumn: boolean;
  marks: readonly GoalEvaluation[];
  month: string;
  goalPeriod: string;
}) {
  return (
    <MetricRow
      goalColumn={goalColumn}
      href={expenseTransactionsHref([category.id], monthBounds(month), null, parent)}
      lead={
        identity ? (
          <CategoryGlyph
            color={identity.effectiveColor}
            decorative
            icon={identity.effectiveIcon}
            label={category.label}
          />
        ) : null
      }
      marks={marks}
      goalPeriod={goalPeriod}
      onCreateGoal={onCreateGoal}
      benefit={
        category.benefitSpending != null && category.benefitAssetCode != null
          ? { assetCode: category.benefitAssetCode, value: category.benefitSpending }
          : null
      }
      label={category.label}
      month={month}
      metric={category}
    />
  );
}
