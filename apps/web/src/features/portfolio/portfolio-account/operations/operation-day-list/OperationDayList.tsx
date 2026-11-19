import type { PortfolioOperation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { DatedList } from '@/components/ui/dated-list/DatedList';
import { dayHeadingLabel } from '@/features/transactions/transaction-list/transaction-day-list/dayHeadingLabel';
import { OperationDayRow } from './OperationDayRow';
import type { OperationRowActions } from './OperationRowMenu';

/** The operations of a portfolio grouped by trade day on the shared dated list. */
export function OperationDayList({
  actions,
  operations,
}: {
  actions: OperationRowActions;
  /** Null while loading: the list mirrors its loaded markup with placeholder rows. */
  operations: PortfolioOperation[] | null;
}) {
  const { i18n, t } = useTranslation();
  const words = {
    today: t('transactions.list.today'),
    yesterday: t('transactions.list.yesterday'),
  };

  return (
    <DatedList
      dayLabel={(day) => dayHeadingLabel(day, i18n.language, words)}
      getDay={(operation) => operation.tradeDate}
      getKey={(operation) => operation.id}
      items={operations}
      label={t('portfolio.account.operations.listLabel')}
      loadingLabel={t('portfolio.account.operations.loading')}
      nextLoadedLabel={t('transactions.pagination.nextLoaded')}
      previousLoadedLabel={t('transactions.pagination.previousLoaded')}
      renderRow={(operation) => <OperationDayRow actions={actions} operation={operation} />}
      skeletonTestId="operations-skeleton"
    />
  );
}
