import type { AccountBalanceSnapshot } from '@cadran/api-client';
import { useCallback } from 'react';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { formatCalendarNumericDay } from '@/lib/decimal';
import type { BalanceWithdrawal } from './useBalanceWithdrawal';
import styles from './BalanceRowActions.module.css';

/**
 * Row actions of one balance snapshot: correct (manual only, when `onEdit` is given) and withdraw
 * with its inline confirmation. A withdrawn or calculated snapshot offers nothing.
 */
export function BalanceRowActions({
  snapshot,
  withdrawal,
  onEdit,
}: {
  snapshot: AccountBalanceSnapshot;
  withdrawal: BalanceWithdrawal;
  onEdit?: (snapshot: AccountBalanceSnapshot) => void;
}) {
  const { t } = useTranslation();
  // The button that opened the confirmation is replaced, so focus moves to the new one.
  const focusOnMount = useCallback((button: HTMLButtonElement | null) => button?.focus(), []);
  if (!snapshot.active || snapshot.source === 'CALCULATED') {
    return null;
  }
  const date = formatCalendarNumericDay(snapshot.asOf);

  if (withdrawal.confirmingId === snapshot.id) {
    return (
      <div className={styles.actions}>
        <button
          aria-describedby={withdrawal.hintId}
          className="primary-action"
          ref={focusOnMount}
          disabled={withdrawal.pending}
          onClick={() => withdrawal.confirm(snapshot)}
          type="button"
        >
          {t(
            withdrawal.pending
              ? 'accounts.snapshots.confirming'
              : 'accounts.snapshots.confirmAction',
          )}
        </button>
        <button
          className="secondary-action"
          disabled={withdrawal.pending}
          onClick={withdrawal.cancel}
          type="button"
        >
          {t('actions.cancel')}
        </button>
      </div>
    );
  }

  return (
    <div className={styles.actions}>
      <ActionMenu
        items={[
          ...(onEdit && snapshot.source === 'MANUAL'
            ? [
                {
                  id: 'edit',
                  icon: 'edit' as const,
                  label: t('accounts.snapshots.editOf', { date }),
                  text: t('accounts.snapshots.edit'),
                  onSelect: () => onEdit(snapshot),
                },
              ]
            : []),
          {
            id: 'withdraw',
            icon: 'delete' as const,
            label: t('accounts.snapshots.withdrawOf', { date }),
            text: t('accounts.snapshots.withdraw'),
            onSelect: () => withdrawal.ask(snapshot),
          },
        ]}
        label={t('accounts.snapshots.actionsOf', { date })}
      />
    </div>
  );
}
