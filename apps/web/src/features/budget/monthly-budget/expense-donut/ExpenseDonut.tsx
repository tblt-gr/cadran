import type { MonthlyLedgerCategoryRow } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { AllocationLegend } from '@/components/ui/charts/allocation-legend/AllocationLegend';
import type { DonutSlice } from '@/components/ui/charts/donut-chart/DonutChart';
import { DonutPlot } from '@/components/ui/charts/donut-chart/DonutPlot';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { SkeletonBlock, SkeletonChartArea } from '@/components/ui/skeleton/Skeleton';
import { canonicalCategoryColor } from '@/features/categories/category-color';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { decimalRatioForGeometry, formatAmount } from '@/lib/decimal';
import { formatRatioPercentage, ratioToPercent } from '@/lib/formatRatioPercentage';
import type { BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import { buildExpenseBreakdown, type ExpenseSlice } from './expenseBreakdown';
import { expenseTransactionsHref } from './expenseTransactionsHref';
import styles from './ExpenseDonut.module.css';

interface ExpenseDonutProps {
  axis: BudgetAxis | null;
  /** The month, inclusive, as the transactions filter reads it. */
  period: { from: string; to: string };
  /** The ledger's expense rows; null while they load, which draws the final layout with blocks. */
  rows: readonly MonthlyLedgerCategoryRow[] | null;
}

const SKELETON_ROWS = 4;

function SliceLabel({ href, text }: { href: string | null; text: string }) {
  const { t } = useTranslation();
  if (href === null) return text;
  return (
    <a
      aria-label={t('budget.monthly.expenseDonut.showTransactions', { label: text })}
      className={styles.link}
      href={href}
      onClick={(event) => handleClientNavigation(event, href)}
    >
      {text}
    </a>
  );
}

/** The month's expenses by category, as a donut and the same figures as a list of links. */
export function ExpenseDonut({ axis, period, rows }: ExpenseDonutProps) {
  const { i18n, t } = useTranslation();
  const titleId = 'monthly-expense-donut-title';
  const breakdown = rows === null ? null : buildExpenseBreakdown(rows, i18n.language);

  const heading = (
    <>
      <h3 id={titleId}>{t('budget.monthly.expenseDonut.title')}</h3>
      <p className={styles.description}>{t('budget.monthly.expenseDonut.description')}</p>
    </>
  );

  if (breakdown === null) {
    return (
      <section aria-labelledby={titleId} className={`card ${styles.card}`}>
        {heading}
        <div className={styles.body}>
          <div className={styles.plotSkeleton}>
            <SkeletonChartArea />
          </div>
          <AllocationLegend
            items={Array.from({ length: SKELETON_ROWS }, (_, index) => ({
              amount: <SkeletonBlock className={styles.amountSkeleton} />,
              barPercent: null,
              key: String(index),
              label: <SkeletonBlock className={styles.labelSkeleton} />,
              share: <SkeletonBlock className={styles.shareSkeleton} />,
            }))}
            label={t('budget.monthly.expenseDonut.legend')}
          />
        </div>
      </section>
    );
  }

  if (breakdown.kind !== 'ready') {
    return (
      <section aria-labelledby={titleId} className={`card ${styles.card}`}>
        {heading}
        <p className={styles.state} role="status">
          {breakdown.kind === 'empty' ? (
            t('budget.monthly.expenseDonut.empty')
          ) : (
            <EmptyValue
              label={t('states.notCalculable.label')}
              reason={t('budget.monthly.expenseDonut.mixedAssets')}
            />
          )}
        </p>
      </section>
    );
  }

  const text = (slice: ExpenseSlice) =>
    slice.grouped
      ? t('budget.monthly.expenseDonut.other', { count: slice.categoryIds.length })
      : slice.label;
  const fill = (slice: ExpenseSlice) => canonicalCategoryColor(slice.color) ?? undefined;
  const donutSlices: DonutSlice[] = breakdown.slices.map((slice) => ({
    amountText: formatAmount(slice.amount, breakdown.assetCode, i18n.language),
    caption: `${text(slice)} · ${formatRatioPercentage(slice.share, i18n.language)}`,
    fill: fill(slice),
    key: slice.key,
    size: decimalRatioForGeometry(slice.share, '1'),
  }));
  const notCalculable = t('states.notCalculable.label');

  return (
    <section aria-labelledby={titleId} className={`card ${styles.card}`}>
      {heading}
      <div className={styles.body}>
        <DonutPlot
          label={t('budget.monthly.expenseDonut.chart')}
          slices={donutSlices}
          totalText={formatAmount(breakdown.total, breakdown.assetCode, i18n.language)}
        />
        <AllocationLegend
          items={[
            ...breakdown.slices.map((slice) => ({
              amount: formatAmount(slice.amount, breakdown.assetCode, i18n.language),
              barPercent: ratioToPercent(slice.share),
              color: fill(slice),
              key: slice.key,
              label: (
                <SliceLabel
                  href={expenseTransactionsHref(slice.categoryIds, period, axis)}
                  text={text(slice)}
                />
              ),
              share: formatRatioPercentage(slice.share, i18n.language),
            })),
            ...breakdown.undrawn.map((item) => ({
              amount: (
                <EmptyValue
                  label={notCalculable}
                  reason={t('budget.monthly.expenseDonut.mixedAssets')}
                />
              ),
              barPercent: null,
              key: item.key,
              label: (
                <SliceLabel
                  href={expenseTransactionsHref([item.key], period, axis)}
                  text={item.label}
                />
              ),
              share: <EmptyValue quiet label={notCalculable} />,
            })),
          ]}
          label={t('budget.monthly.expenseDonut.legend')}
        />
      </div>
    </section>
  );
}
