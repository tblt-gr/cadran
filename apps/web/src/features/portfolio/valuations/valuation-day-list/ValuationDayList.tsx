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
export function ValuationDayList({ loadingLabel, valuations, ...actions }: ValuationDayListProps) {
  const { t, i18n } = useTranslation();
  const words = {
    today: t('transactions.list.today'),
    yesterday: t('transactions.list.yesterday'),
  };
  return (
    <DatedList
      dayLabel={(day) => dayHeadingLabel(day, i18n.language, words)}
      getDay={(valuation) => valuation.valuedOn}
      getKey={(valuation) => valuation.id}
      items={valuations}
      label={t('portfolio.valuations.list.label')}
      loadingLabel={loadingLabel ?? t('portfolio.valuations.list.loading')}
      nextLoadedLabel={t('portfolio.valuations.list.next')}
      previousLoadedLabel={t('portfolio.valuations.list.previous')}
      renderRow={(valuation) => <ValuationDayRow {...actions} valuation={valuation} />}
      skeletonTestId="valuations-skeleton"
    />
  );
}
