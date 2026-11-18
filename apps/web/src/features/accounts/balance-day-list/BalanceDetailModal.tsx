import type { AccountBalanceSnapshot } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { Modal } from '@/components/ui/modal/Modal';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';

/** Read-only detail of one recorded balance: what the row has no room for. */
export function BalanceDetailModal({
  snapshot,
  close,
}: {
  snapshot: AccountBalanceSnapshot;
  close: () => void;
}) {
  const { i18n, t } = useTranslation();
  const recorded = new Date(snapshot.recordedAt);

  return (
    <Modal
      close={close}
      intent="detail"
      title={t('accounts.snapshots.detailTitle', { date: formatCalendarNumericDay(snapshot.asOf) })}
    >
      <dl>
        <dt>{t('accounts.snapshots.amount')}</dt>
        <dd>
          {snapshot.amount ? (
            formatAmount(snapshot.amount.value, snapshot.amount.assetCode, i18n.language)
          ) : (
            <EmptyValue label={t('accounts.balanceTab.nonComputable')} />
          )}
        </dd>
        <dt>{t('accounts.snapshots.source')}</dt>
        <dd>{t(`accounts.balances.sources.${snapshot.source}`)}</dd>
        <dt>{t('accounts.balanceTab.reconciliation')}</dt>
        <dd>
          {t(
            snapshot.reconciliationStatus === 'RECONCILED'
              ? 'accounts.balanceTab.reconciled'
              : 'accounts.balanceTab.unreconciled',
          )}
        </dd>
        <dt>{t('accounts.snapshots.state')}</dt>
        <dd>{t(snapshot.active ? 'accounts.snapshots.active' : 'accounts.snapshots.withdrawn')}</dd>
        <dt>{t('accounts.snapshots.comment')}</dt>
        <dd>
          {snapshot.comment ? (
            snapshot.comment
          ) : (
            <EmptyValue label={t('accounts.snapshots.noComment')} />
          )}
        </dd>
        <dt>{t('accounts.snapshots.recordedAt')}</dt>
        <dd>
          <time dateTime={snapshot.recordedAt}>
            {new Intl.DateTimeFormat(i18n.language, {
              dateStyle: 'medium',
              timeStyle: 'short',
            }).format(recorded)}
          </time>
        </dd>
      </dl>
    </Modal>
  );
}
