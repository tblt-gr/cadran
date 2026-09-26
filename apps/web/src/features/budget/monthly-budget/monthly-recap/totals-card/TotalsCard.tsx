import type {
  MonthlyRecapAxis,
  MonthlyRecapCategory,
  MonthlyRecapMetric,
  MonthlyRecapTotals,
  MonthlyKpiSourceTransaction,
} from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { formatAmount } from '@/lib/decimal';
import { formatRatioPercentage } from '@/lib/formatRatioPercentage';
import { MetricExplanationModal } from './MetricExplanationModal';
import styles from './TotalsCard.module.css';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';

interface TotalsCardProps {
  month: string;
  onConfigure: () => void;
  owner: boolean;
  totals: MonthlyRecapTotals;
  visibleCategoryIds: string[];
  visibleAxes: string[];
}

type RecapMetric = Pick<
  MonthlyRecapMetric,
  'assetCode' | 'kpi' | 'pendingCount' | 'sourceTransactionIds' | 'sourceTransferIds' | 'value'
> & {
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
  rate = false,
}: {
  axis?: MonthlyRecapAxis['axis'];
  label?: string;
  month: string;
  metric: RecapMetric;
  rate?: boolean;
}) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(false);
  const label =
    suppliedLabel ??
    (axis
      ? t(`budget.monthly.axes.${axis}`)
      : metric.kpi
        ? t(`reports.metrics.${metric.kpi}`)
        : t('states.notCalculable.label'));
  return (
    <li className={styles.row}>
      <div>
        <span className={styles.label}>
          <span>{label}</span>
          <InfoButton
            aria-haspopup="dialog"
            label={t('reports.explainKpi', { kpi: label })}
            onClick={() => setOpen(true)}
          />
        </span>
        <Value metric={metric} rate={rate} />
      </div>
      {open ? (
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

function CategoryRow({ category, month }: { category: MonthlyRecapCategory; month: string }) {
  return <MetricRow label={category.label} month={month} metric={category} />;
}

export function TotalsCard({
  month,
  onConfigure,
  owner,
  totals,
  visibleAxes,
  visibleCategoryIds,
}: TotalsCardProps) {
  const { t } = useTranslation();
  const axes = totals.expensesByAxis.filter((axis) => visibleAxes.includes(axis.axis));
  const categories = totals.categories.filter((category) =>
    visibleCategoryIds.includes(category.id),
  );
  return (
    <section aria-labelledby="monthly-recap-totals-title" className={`card ${styles.panel}`}>
      <header className={styles.heading}>
        <div className={styles.headingTitle}>
          <h3 id="monthly-recap-totals-title">{t('budget.monthly.recap.totals.title')}</h3>
          {owner ? (
            <button className="secondary-action" onClick={onConfigure} type="button">
              {t('budget.monthly.recap.preferences.open')}
            </button>
          ) : null}
        </div>
      </header>
      <ul className={styles.rows}>
        <MetricRow month={month} metric={totals.cashIncome} />
        <MetricRow month={month} metric={totals.budgetExpenses} />
        <MetricRow month={month} metric={totals.savingsInflows} />
        <MetricRow month={month} metric={totals.savingsWithdrawals} />
        <MetricRow month={month} metric={totals.netSavingsTransfers} />
        <MetricRow month={month} metric={totals.netSavingsRate} rate />
      </ul>
      <h4>{t('budget.monthly.recap.totals.axes')}</h4>
      {axes.length ? (
        <ul className={styles.rows}>
          {axes.map((axis) => (
            <MetricRow axis={axis.axis} key={axis.axis} month={month} metric={axis} />
          ))}
        </ul>
      ) : (
        <p className={styles.empty}>{t('budget.monthly.recap.totals.axesEmpty')}</p>
      )}
      <h4>{t('budget.monthly.recap.totals.categories')}</h4>
      {categories.length ? (
        <ul className={styles.rows}>
          {categories.map((category) => (
            <CategoryRow category={category} key={category.id} month={month} />
          ))}
        </ul>
      ) : (
        <p className={styles.empty}>{t('budget.monthly.recap.totals.categoriesEmpty')}</p>
      )}
    </section>
  );
}
