import type { PortfolioValuationSummary } from '@cadran/api-client';
import { DatedList } from '@/components/ui/dated-list/DatedList';
import { dayHeadingLabel } from '@/features/transactions/transaction-list/transaction-day-list/dayHeadingLabel';
import { useTranslation } from 'react-i18next';
import { ValuationDayRow, type ValuationRowActions } from './ValuationDayRow';

interface ValuationDayListProps extends ValuationRowActions {
  /** Announced once while loading. */
  loadingLabel?: string;
  /** Pending rows: the loading list mirrors the loaded markup element for element. */
  valuations: PortfolioValuationSummary[] | null;
}

/** Active valuations grouped by valuation date, newest first, on the shared dated list. */
export function ValuationDayList({
  loadingLabel = 'Chargement des valorisations…',
  valuations,
  ...actions
}: ValuationDayListProps) {
  const { i18n } = useTranslation();
  const words = { today: 'Aujourd’hui', yesterday: 'Hier' };
  return (
    <DatedList
      dayLabel={(day) => dayHeadingLabel(day, i18n.language, words)}
      getDay={(valuation) => valuation.valuedOn}
      getKey={(valuation) => valuation.id}
      items={valuations}
      label="Valorisations du portefeuille"
      loadingLabel={loadingLabel}
      nextLoadedLabel="Valorisations suivantes"
      previousLoadedLabel="Valorisations précédentes"
      renderRow={(valuation) => <ValuationDayRow {...actions} valuation={valuation} />}
      skeletonTestId="valuations-skeleton"
    />
  );
}
