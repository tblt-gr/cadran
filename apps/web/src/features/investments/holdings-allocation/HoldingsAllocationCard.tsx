import { InstrumentLink } from '@/features/investments/instrument-link/InstrumentLink';
import type { Holdings } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import {
  AllocationBreakdown,
  type AllocationBreakdownSlice,
} from '@/components/ui/charts/allocation-breakdown/AllocationBreakdown';
import {
  geometrySize,
  isNegativeRatio,
} from '@/components/ui/charts/allocation-breakdown/signedRatio';
import { sliceCaption } from '@/features/dashboard/allocation-charts/sliceCaption';
import { formatRatioPercentage, ratioToPercent } from '@/lib/formatRatioPercentage';
import { holdingsMoney } from '@/features/investments/holdingsFormat';
import { sliceLabel, type AllocationCriterion } from './allocationLabels';

const criterionNameKeys = {
  byAssetClass: 'investments.allocation.criterionName.assetClass',
  byRegion: 'investments.allocation.criterionName.region',
  byInstrument: 'investments.allocation.criterionName.instrument',
  byAccount: 'investments.allocation.criterionName.account',
} as const;

/** Share of the securities by asset class, region, instrument or account, in the shared panel. */
export function HoldingsAllocationCard({
  holdings,
  view,
}: {
  holdings: Holdings | null;
  /** The positions view on the page: by account, the allocation starts by account too. */
  view?: 'asset' | 'account';
}) {
  const { t, i18n } = useTranslation();
  const [chosen, setChosen] = useState<AllocationCriterion | null>(null);
  // Without any line the asset views only hold the unsplit slice: the accounts are what to show.
  const criterion: AllocationCriterion =
    chosen ??
    (view === 'account' || (holdings && holdings.items.length === 0)
      ? 'byAccount'
      : 'byAssetClass');
  const raw = holdings?.allocation[criterion] ?? [];
  const label = (slice: { key: string; label: string }) =>
    sliceLabel(criterion, slice, t('investments.allocation.unspecified'), t);
  const money = (value: string) => holdingsMoney(value, holdings!.assetCode, i18n.language);
  const charted = raw.length > 0 && raw.every((slice) => slice.share !== null);
  const ignored = raw.filter((slice) => isNegativeRatio(slice.share)).length;

  const slices: AllocationBreakdownSlice[] | null = holdings
    ? raw.map((slice) => ({
        key: slice.key,
        caption: sliceCaption(
          label(slice),
          slice.share === null ? null : ratioToPercent(slice.share),
        ),
        label:
          criterion === 'byInstrument' && slice.key !== 'UNSPLIT' ? (
            <InstrumentLink instrument={{ id: slice.key, name: label(slice) }} />
          ) : (
            label(slice)
          ),
        amountText: money(slice.value),
        shareText: slice.share === null ? null : formatRatioPercentage(slice.share, i18n.language),
        size: charted ? geometrySize(slice.share) : null,
        // Accounts valued as a total stand for no category: a neutral fill, no colour meaning.
        fill: slice.key === 'UNSPLIT' ? 'var(--text-muted)' : undefined,
      }))
    : null;

  return (
    <AllocationBreakdown
      criteria={[
        { value: 'byAssetClass', label: t('investments.allocation.assetClass') },
        { value: 'byRegion', label: t('investments.allocation.region') },
        { value: 'byInstrument', label: t('investments.allocation.instrument') },
        { value: 'byAccount', label: t('investments.allocation.account') },
      ]}
      criterion={criterion}
      donutLabel={t('investments.allocation.donut', { criterion: t(criterionNameKeys[criterion]) })}
      onCriterionChange={setChosen}
      ignoredCount={ignored}
      slices={slices}
      status={
        charted
          ? { kind: 'ready' }
          : { kind: 'notCalculable', message: t('allocation.notCalculable') }
      }
      title={t('allocation.title')}
      titleId="holdings-allocation-title"
      totalText={
        !holdings || holdings.totals.securitiesValue === null
          ? null
          : money(holdings.totals.securitiesValue)
      }
    />
  );
}
