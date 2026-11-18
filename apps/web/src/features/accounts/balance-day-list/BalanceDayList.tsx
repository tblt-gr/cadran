import type { AccountBalanceSnapshot } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { DatedList } from '@/components/ui/dated-list/DatedList';
import type { BalanceWithdrawal } from '@/features/accounts/record-balance-form/balance-snapshots/useBalanceWithdrawal';
import { dayHeadingLabel } from '@/features/transactions/transaction-list/transaction-day-list/dayHeadingLabel';
import { BalanceDayRow } from './BalanceDayRow';
import { BalanceDetailModal } from './BalanceDetailModal';

interface Props {
  /** Newest first; `null` while loading, when the list mirrors its loaded markup. */
  items: readonly AccountBalanceSnapshot[] | null;
  /** Absent while loading, when no row carries an action. */
  withdrawal?: BalanceWithdrawal;
  onEdit?: (snapshot: AccountBalanceSnapshot) => void;
  announce?: boolean;
  headingLevel?: 3 | 4;
  /** Accessible name of the list. */
  label: string;
  loadingLabel: string;
}

/** Recorded balances grouped by day on the shared dated list; a click opens the detail dialog. */
export function BalanceDayList({
  announce,
  headingLevel,
  items,
  label,
  loadingLabel,
  onEdit,
  withdrawal,
}: Props) {
  const { i18n, t } = useTranslation();
  const [detailId, setDetailId] = useState<string | null>(null);
  const detail = items?.find((snapshot) => snapshot.id === detailId) ?? null;
  const words = {
    today: t('transactions.list.today'),
    yesterday: t('transactions.list.yesterday'),
  };

  return (
    <>
      <DatedList
        announce={announce}
        dayLabel={(day) => dayHeadingLabel(day, i18n.language, words)}
        getDay={(snapshot) => snapshot.asOf}
        getKey={(snapshot) => snapshot.id}
        headingLevel={headingLevel}
        items={items}
        label={label}
        loadingLabel={loadingLabel}
        nextLoadedLabel={t('transactions.pagination.nextLoaded')}
        previousLoadedLabel={t('transactions.pagination.previousLoaded')}
        renderRow={(snapshot) => (
          <BalanceDayRow
            onEdit={onEdit}
            onOpen={(opened) => setDetailId(opened.id)}
            snapshot={snapshot}
            withdrawal={withdrawal}
          />
        )}
        skeletonTestId="balance-day-list-skeleton"
      />
      {detail ? <BalanceDetailModal close={() => setDetailId(null)} snapshot={detail} /> : null}
    </>
  );
}
