import type { GroupCompositionChartReason, NetWorthAmount } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { DonutChart, type DonutSlice } from '@/components/ui/charts/donut-chart/DonutChart';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import { groupColor } from '@/features/accounts/group-color';
import { allocationSize } from '@/features/dashboard/allocation-charts/allocationSize';
import { sliceCaption } from '@/features/dashboard/allocation-charts/sliceCaption';
import { useBoxSize } from '@/hooks/use-box-size';
import { formatAmount } from '@/lib/decimal';
import type { CompositionEntry } from './compositionEntries';
import styles from './GroupComposition.module.css';

interface GroupCompositionChartProps {
  /** Why a pie would mislead; the table below stays available. */
  chartReason: GroupCompositionChartReason | null;
  /** Null while the composition is being read: only the drawing is a block. */
  entries: CompositionEntry[] | null;
  label: string;
  total: NetWorthAmount | null;
}

function amountText(amount: NetWorthAmount | null, locale: string): string | null {
  if (amount === null) return null;
  const shown = amount.display ?? { value: amount.value, assetCode: amount.assetCode };
  return formatAmount(shown.value, shown.assetCode, locale);
}

/** Donut sized from the server ratio, or the text reason the pie is withheld. */
export function GroupCompositionChart({
  chartReason,
  entries,
  label,
  total,
}: GroupCompositionChartProps) {
  const { i18n, t } = useTranslation();
  const { ref, size } = useBoxSize();

  if (entries !== null && chartReason !== null) {
    return <p className={styles.reason}>{t(`accounts.composition.chartReasons.${chartReason}`)}</p>;
  }

  const slices: DonutSlice[] = (entries ?? []).flatMap((entry) => {
    const weight = entry.share.percent === null ? null : allocationSize(entry.share.percent);
    if (weight === null) return [];
    return [
      {
        amountText: amountText(entry.value, i18n.language),
        caption: sliceCaption(entry.label, entry.share.percentDisplay),
        fill: groupColor(entry.color),
        key: entry.key,
        size: weight,
      },
    ];
  });
  const ready = size.width > 0 && size.height > 0;

  return (
    <div className={styles.plot} data-visual-mask ref={ref}>
      {entries === null ? (
        <SkeletonBlock className={styles.plotSkeleton} />
      ) : ready && slices.length > 0 ? (
        <DonutChart
          height={size.height}
          label={label}
          slices={slices}
          totalText={amountText(total, i18n.language)}
          width={size.width}
        />
      ) : null}
    </div>
  );
}
