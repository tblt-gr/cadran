import type { NetWorthAllocationEntry, NetWorthReason } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { AllocationLegend as Legend } from '@/components/ui/charts/allocation-legend/AllocationLegend';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { NetWorthFigure } from '@/features/dashboard/net-worth-figure/NetWorthFigure';
import { formatSharePercent } from '@/lib/formatSharePercent';

interface AllocationLegendProps {
  entries: NetWorthAllocationEntry[];
  reason: NetWorthReason | null;
}

/** Top-level exclusive net-worth groups as the shared legend: label, backend share, amount, bar. */
export function AllocationLegend({ entries, reason }: AllocationLegendProps) {
  const { t } = useTranslation();

  return (
    <Legend
      items={entries.map((entry) => ({
        amount: <NetWorthFigure amount={entry.value} reason={reason} />,
        barPercent: entry.share.percentDisplay,
        key: entry.groupId,
        label: entry.label,
        share:
          entry.share.percentDisplay === null ? (
            <EmptyValue label={t('states.notCalculable.label')} />
          ) : (
            formatSharePercent(entry.share.percentDisplay, { fractionDigits: 2 })
          ),
      }))}
      label={t('dashboard.allocation.title')}
    />
  );
}
