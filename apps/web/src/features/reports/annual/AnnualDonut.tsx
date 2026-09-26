import { useTranslation } from 'react-i18next';
import { AllocationLegend } from '@/components/ui/charts/allocation-legend/AllocationLegend';
import { DonutPlot } from '@/components/ui/charts/donut-chart/DonutPlot';
import type { DonutSlice } from '@/components/ui/charts/donut-chart/DonutChart';
import { decimalRatioForGeometry, formatAmount } from '@/lib/decimal';
import { formatRatioPercentage, ratioToPercent } from '@/lib/formatRatioPercentage';
import styles from './AnnualDonut.module.css';

export interface AnnualDonutItem {
  /** Exact backend amount, shown on hover and in the legend. */
  amount: string;
  key: string;
  label: string;
  /** Exact backend ratio in [0, 1]; only its bounded ratio is used to draw. */
  share: string;
}

interface AnnualDonutProps {
  assetCode: string;
  items: AnnualDonutItem[];
  label: string;
}

/** The dashboard allocation donut and legend, fed with the annual report's exact strings. */
export function AnnualDonut({ assetCode, items, label }: AnnualDonutProps) {
  const { i18n, t } = useTranslation();
  const slices: DonutSlice[] = items.map((item) => {
    const amountText = formatAmount(item.amount, assetCode, i18n.language);
    return {
      amountText,
      caption: `${item.label} · ${formatRatioPercentage(item.share, i18n.language)}`,
      key: item.key,
      size: decimalRatioForGeometry(item.share, '1'),
    };
  });

  return (
    <div className={styles.donut}>
      <DonutPlot label={t('reports.annual.charts.donut')} slices={slices} totalText={null} />
      <AllocationLegend
        items={items.map((item) => ({
          amount: formatAmount(item.amount, assetCode, i18n.language),
          barPercent: ratioToPercent(item.share),
          key: item.key,
          label: item.label,
          share: formatRatioPercentage(item.share, i18n.language),
        }))}
        label={label}
      />
    </div>
  );
}
