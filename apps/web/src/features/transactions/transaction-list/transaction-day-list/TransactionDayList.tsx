import type { Account, Transaction } from '@cadran/api-client';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { DatedList } from '@/components/ui/dated-list/DatedList';
import type { RowSelection } from '@/features/transactions/bulk-selection/useBulkSelection';
import { dayHeadingLabel } from './dayHeadingLabel';
import { orderTransferLegs } from './orderTransferLegs';
import { TransactionDayRow } from './TransactionDayRow';
import { transferCounterparts } from './transferCounterparts';

interface TransactionDayListProps {
  /** Names each row's account. Left out on a list already scoped to one account. */
  accounts?: Account[];
  headingLevel?: 3 | 4;
  /** Accessible name of the list; defaults to the transactions caption. */
  label?: string;
  /** Adds a checkbox per row for bulk editing. */
  selection?: RowSelection;
  /** Pending rows: the loading list mirrors the loaded markup element for element. */
  transactions: Transaction[] | null;
  announce?: boolean;
}

/** The transaction register: transactions grouped by booking day on the shared dated list. */
export function TransactionDayList({
  accounts,
  announce = true,
  headingLevel = 3,
  label,
  selection,
  transactions,
}: TransactionDayListProps) {
  const { i18n, t } = useTranslation();
  const words = {
    today: t('transactions.list.today'),
    yesterday: t('transactions.list.yesterday'),
  };
  const ordered = useMemo(
    () => (transactions ? orderTransferLegs(transactions) : null),
    [transactions],
  );
  const counterparts = transactions ? transferCounterparts(transactions, accounts) : null;

  return (
    <DatedList
      announce={announce}
      dayLabel={(day) => dayHeadingLabel(day, i18n.language, words)}
      getDay={(transaction) => transaction.bookedOn}
      getKey={(transaction) => transaction.id}
      headingLevel={headingLevel}
      items={ordered}
      label={label ?? t('transactions.list.caption')}
      loadingLabel={t('transactions.loading')}
      nextLoadedLabel={t('transactions.pagination.nextLoaded')}
      previousLoadedLabel={t('transactions.pagination.previousLoaded')}
      renderRow={(transaction, context) => (
        <TransactionDayRow
          accounts={accounts}
          counterpartAccount={transaction ? counterparts?.get(transaction.id) : undefined}
          selection={context.selection}
          transaction={transaction}
        />
      )}
      selection={selection}
      skeletonTestId="transaction-day-list-skeleton"
    />
  );
}
