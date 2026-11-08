import type {
  MonthlyProjectionMetric,
  MonthlyRecapAxis,
  MonthlyRecapCategory,
  MonthlyRecapMetric,
  MonthlyRecapTotals,
  MonthlyKpiSourceTransaction,
} from '@cadran/api-client';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { MetricLabelButton } from './MetricLabelButton';
import { RecapSettingsButton } from './RecapSettingsButton';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { MetricExplanationModal } from './MetricExplanationModal';
import type { GoalEvaluation } from '@cadran/api-client';
import { GoalEvaluationsError } from '@/features/goals/goal-mark/GoalEvaluationsError';
import { GoalColumnHead, GoalMarkedValue, GoalTarget } from '@/features/goals/goal-mark/GoalMark';
import { marksFor } from '@/features/goals/goal-mark/goalMarks';
import { useGoalMarks } from '@/features/reports/useGoalMarks';
import { UpcomingNotice } from '@/features/settings/interface/UpcomingNotice';
import { useMonthlyReport } from '@/features/reports/useMonthlyReport';
import { RATE_KPIS, RECAP_KPIS, type RecapKpi } from './recapKpis';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import styles from './TotalsCard.module.css';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';

interface TotalsCardProps {
  info?: ReactNode;
  month: string;
  onConfigure: () => void;
  owner: boolean;
  totals: MonthlyRecapTotals;
  visibleCategoryIds: string[];
  visibleAxes: string[];
}

/** A projection figure shaped like a recap one, so one row renders both. */
function asRecapMetric(kpi: RecapKpi, metric: MonthlyProjectionMetric): RecapMetric {
  return { ...metric, kpi, pendingCount: 0, sourceTransactionIds: [], sourceTransferIds: [] };
}

type RecapMetric = Pick<
  MonthlyRecapMetric,
  'assetCode' | 'pendingCount' | 'sourceTransactionIds' | 'sourceTransferIds' | 'value'
> & {
  kpi: RecapKpi | null;
  reason: MonthlyRecapMetric['reason'] | MonthlyRecapCategory['reason'];
  sourceTransactions?: MonthlyKpiSourceTransaction[];
};

const reasonKeys = {
  INCOMPLETE_TRANSFER_PAIR: 'budget.monthly.recap.reasons.INCOMPLETE_TRANSFER_PAIR',
  MISMATCHED_TRANSFER_PAIR: 'budget.monthly.recap.reasons.MISMATCHED_TRANSFER_PAIR',
  MIXED_ASSETS: 'budget.monthly.recap.reasons.MIXED_ASSETS',
  MISSING_ACCOUNT_CLASSIFICATION: 'budget.monthly.recap.reasons.MISSING_ACCOUNT_CLASSIFICATION',
  MISSING_BENEFIT_SOURCE: 'budget.monthly.recap.reasons.MISSING_BENEFIT_SOURCE',
  MISSING_VALUATION: 'budget.monthly.recap.reasons.MISSING_VALUATION',
  MIXED_METRIC_POLICIES: 'budget.monthly.recap.reasons.MIXED_METRIC_POLICIES',
  UNKNOWN_METRIC_POLICY: 'budget.monthly.recap.reasons.UNKNOWN_METRIC_POLICY',
  NO_ACCOUNT: 'budget.monthly.recap.reasons.NO_ACCOUNT',
  NO_ELIGIBLE_ACCOUNT: 'budget.monthly.recap.reasons.NO_ELIGIBLE_ACCOUNT',
  NO_MOVEMENTS: 'budget.monthly.recap.reasons.NO_MOVEMENTS',
  ZERO_CASH_INCOME: 'budget.monthly.recap.reasons.ZERO_CASH_INCOME',
} as const satisfies Record<NonNullable<RecapMetric['reason']>, string>;

const UNAVAILABLE: MonthlyProjectionMetric = { value: null, assetCode: null, reason: null };

function Value({ metric, rate }: { metric: RecapMetric; rate?: boolean }) {
  const { i18n, t } = useTranslation();
  if (metric.value === null || (metric.assetCode === null && !rate)) {
    return (
      <EmptyValue
        label={t('states.notCalculable.label')}
        reason={metric.reason ? t(reasonKeys[metric.reason]) : null}
      />
    );
  }
  if (rate) return <MoneyValue value={formatRatioPercentage(metric.value, i18n.language)} />;
  return <MoneyValue value={formatAmount(metric.value, metric.assetCode!, i18n.language)} />;
}

function MetricRow({
  axis,
  label: suppliedLabel,
  month,
  metric,
  marks = [],
  goalPeriod,
  goalColumn = false,
  rate = false,
  benefit = null,
}: {
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
  return (
    <li className={styles.row}>
      <div className={goalColumn ? styles.withGoals : undefined}>
        {marks.length > 0 ? (
          // A goal's `i` replaces the calculation explanation of the row.
          <span className={styles.labelText}>{label}</span>
        ) : (
          <MetricLabelButton label={label} onClick={() => setOpen(true)} />
        )}
        {metric === null ? (
          <SkeletonBlock className={styles.amountSkeleton} />
        ) : (
          <GoalMarkedValue items={marks}>
            <Value metric={metric} rate={rate} />
          </GoalMarkedValue>
        )}
        {goalColumn ? (
          <span className={styles.goal}>
            {goalPeriod ? <GoalTarget items={marks} period={goalPeriod} /> : null}
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

function CategoryRow({
  category,
  marks,
  goalColumn,
  month,
  goalPeriod,
}: {
  category: MonthlyRecapCategory;
  goalColumn: boolean;
  marks: readonly GoalEvaluation[];
  month: string;
  goalPeriod: string;
}) {
  return (
    <MetricRow
      goalColumn={goalColumn}
      marks={marks}
      goalPeriod={goalPeriod}
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

export function TotalsCard({
  info,
  month,
  onConfigure,
  owner,
  totals,
  visibleAxes,
  visibleCategoryIds,
}: TotalsCardProps) {
  const { t } = useTranslation();
  // The recap carries six of the indicators; the month's other flows come from the monthly projection.
  const projection = useMonthlyReport(month);
  // A month's goals mark the rows that carry their figure; the rows stay as they are while the
  // evaluations load or fail.
  const goals = useGoalMarks({ from: month, to: month, granularity: 'MONTH' });
  const marksOf = (columnKey: string) => marksFor(goals.evaluations?.months, month, columnKey);
  const axes = totals.expensesByAxis.filter((axis) => visibleAxes.includes(axis.axis));
  const categories = totals.categories.filter((category) =>
    visibleCategoryIds.includes(category.id),
  );
  return (
    <section aria-labelledby="monthly-recap-totals-title" className={`card ${styles.panel}`}>
      <header className={styles.heading}>
        <div className={styles.headingTitle}>
          <div className={styles.titleGroup}>
            <h3 id="monthly-recap-totals-title">{t('budget.monthly.recap.totals.title')}</h3>
            {info}
          </div>
          {owner ? <RecapSettingsButton onClick={onConfigure} /> : null}
        </div>
      </header>
      <UpcomingNotice
        includesUpcoming={projection.data?.includesUpcoming ?? false}
        upcomingCount={projection.data?.upcomingCount ?? 0}
      />
      {goals.shown ? (
        <p className={styles.goalHead}>
          <GoalColumnHead />
        </p>
      ) : null}
      <ul className={styles.rows}>
        {RECAP_KPIS.map((kpi) => {
          const own =
            kpi in totals ? (totals[kpi as keyof MonthlyRecapTotals] as RecapMetric) : null;
          const extra = projection.data?.[kpi];
          const metric =
            own ??
            (extra
              ? asRecapMetric(kpi, extra)
              : projection.isError
                ? asRecapMetric(kpi, UNAVAILABLE)
                : null);
          return (
            <MetricRow
              key={kpi}
              label={t(`reports.metrics.${kpi}`)}
              goalColumn={goals.shown}
              marks={marksOf(kpi)}
              metric={metric}
              goalPeriod={month}
              month={month}
              rate={RATE_KPIS.includes(kpi)}
            />
          );
        })}
      </ul>
      {axes.length ? (
        <section className={styles.section}>
          <h4>
            {t('budget.monthly.recap.totals.axes')}
            {goals.shown ? <GoalColumnHead /> : null}
          </h4>
          <ul className={styles.rows}>
            {axes.map((axis) => (
              <MetricRow
                axis={axis.axis}
                key={axis.axis}
                goalColumn={goals.shown}
                marks={marksOf(`axis:${axis.axis}`)}
                month={month}
                metric={axis}
                goalPeriod={month}
              />
            ))}
          </ul>
        </section>
      ) : null}
      {categories.length ? (
        <section className={styles.section}>
          <h4>
            {t('budget.monthly.recap.totals.categories')}
            {goals.shown ? <GoalColumnHead /> : null}
          </h4>
          <ul className={styles.rows}>
            {categories.map((category) => (
              <CategoryRow
                category={category}
                key={category.id}
                goalColumn={goals.shown}
                marks={marksOf(`category:${category.id}`)}
                month={month}
                goalPeriod={month}
              />
            ))}
          </ul>
        </section>
      ) : null}
      {goals.failed ? <GoalEvaluationsError onRetry={goals.retry} /> : null}
    </section>
  );
}
