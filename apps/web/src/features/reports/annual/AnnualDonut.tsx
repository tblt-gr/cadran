import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { AllocationLegend } from '@/components/ui/charts/allocation-legend/AllocationLegend';
import { DonutPlot } from '@/components/ui/charts/donut-chart/DonutPlot';
import { CategoryLabel } from '@/features/categories/category-label/CategoryLabel';
import type { DonutSlice } from '@/components/ui/charts/donut-chart/DonutChart';
import { decimalRatioForGeometry, formatAmount, sumDecimals } from '@/lib/decimal';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatRatioPercentage, ratioToPercent } from '@/lib/formatRatioPercentage';
import styles from './AnnualDonut.module.css';

export interface AnnualDonutItem {
  /** Exact backend amount, shown on hover. */
  amount: string;
  /** Canonical identity color; the default palette applies when absent. */
  color?: string;
  key: string;
  label: string;
  /** Exact backend ratio in [0, 1]; only its bounded ratio is used to draw. */
  share: string;
}

/** One legend row; a null amount or share is not calculable and keeps its reason. */
export interface AnnualLegendRow {
  amount: string | null;
  color?: string;
  /** Where the row's figure lives (its transactions); the label is a plain text without it. */
  href?: string | null;
  /** A category row's icon (null when it has none); absent on rows that are not categories. */
  icon?: string | null;
  key: string;
  label: string;
  reason?: string | null;
  share: string | null;
}

interface AnnualDonutProps {
  assetCode: string;
  /** What the donut draws. */
  slices: AnnualDonutItem[];
  /** The text alternative: every row, including those the donut groups or cannot draw. */
  legend: AnnualLegendRow[];
  label: string;
  /** Rows shown before "Voir plus"; every row when omitted. */
  visibleRows?: number;
}

/** The dashboard allocation donut and legend, fed with the annual report's exact strings. */
export function AnnualDonut({ assetCode, legend, label, slices, visibleRows }: AnnualDonutProps) {
  const { i18n, t } = useTranslation();
  const notCalculable = t('reports.annual.notCalculable');
  const donutSlices: DonutSlice[] = slices.map((item) => ({
    amountText: formatAmount(item.amount, assetCode, i18n.language),
    fill: item.color,
    caption: `${item.label} · ${formatRatioPercentage(item.share, i18n.language)}`,
    key: item.key,
    size: decimalRatioForGeometry(item.share, '1'),
  }));

  // The centre rests on the total of what the chart measures: the exact sum of every row, or
  // nothing when one row cannot be calculated.
  const amounts = legend.map((row) => row.amount);
  const total =
    amounts.includes(null) || amounts.length === 0 ? null : sumDecimals(amounts as string[]);

  const rowLabel = (row: AnnualLegendRow) => {
    const text =
      row.icon === undefined ? (
        row.label
      ) : (
        <CategoryLabel color={row.color} icon={row.icon}>
          {row.label}
        </CategoryLabel>
      );
    const href = row.href;
    if (!href) return text;
    return (
      <a
        aria-label={t('budget.monthly.expenseDonut.showTransactions', { label: row.label })}
        className={styles.link}
        href={href}
        onClick={(event) => handleClientNavigation(event, href)}
      >
        {text}
      </a>
    );
  };

  return (
    <div className={styles.donut}>
      <DonutPlot
        label={t('reports.annual.charts.donut')}
        slices={donutSlices}
        totalText={total === null ? null : formatAmount(total, assetCode, i18n.language)}
      />
      <AllocationLegend
        collapse={
          visibleRows === undefined
            ? undefined
            : {
                count: visibleRows,
                lessLabel: t('reports.annual.charts.showLess'),
                moreLabel: t('reports.annual.charts.showMore'),
              }
        }
        items={legend.map((row) => ({
          amount:
            row.amount === null ? (
              <EmptyValue
                quiet
                label={notCalculable}
                reason={
                  row.reason
                    ? t(`reports.annual.reasons.${row.reason}`, { defaultValue: row.reason })
                    : null
                }
              />
            ) : (
              formatAmount(row.amount, assetCode, i18n.language)
            ),
          color: row.color,
          barPercent: row.share === null ? null : ratioToPercent(row.share),
          key: row.key,
          label: rowLabel(row),
          share:
            row.share === null ? (
              <EmptyValue quiet label={notCalculable} />
            ) : (
              formatRatioPercentage(row.share, i18n.language)
            ),
        }))}
        label={label}
      />
    </div>
  );
}
