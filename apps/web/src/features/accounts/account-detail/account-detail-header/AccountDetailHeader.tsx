import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './AccountDetailHeader.module.css';

/**
 * The back link, the visually hidden page title and the row of account-level
 * actions (rules, reconcile, transfer, transaction). A named, self-contained
 * section: it renders only from the account and the creatable flag, and its
 * own responsive layout (actions stack full-width on narrow screens).
 */
export function AccountDetailHeader({
  account,
  creatable,
  onAddTransaction,
  onAddTransfer,
  onOpenRules,
  onReconcile,
}: {
  account: Account;
  creatable: boolean;
  onAddTransaction: () => void;
  onAddTransfer: () => void;
  onOpenRules: () => void;
  onReconcile: () => void;
}) {
  const { t } = useTranslation();

  return (
    <section aria-labelledby="account-detail-title" className={styles.intro}>
      <div>
        <div className={styles.titleRow}>
          <a
            aria-label={t('accounts.detail.back')}
            className={`icon-button ${styles.back}`}
            href="/accounts"
            onClick={(event) => handleClientNavigation(event, '/accounts')}
          >
            <Icon name="arrow-left" size={18} />
          </a>
          <p>{t('accounts.detail.eyebrow')}</p>
        </div>
        <h2 className="sr-only" id="account-detail-title">
          {account.label}
        </h2>
      </div>
      <div className={styles.actions}>
        <button className="secondary-action" onClick={onOpenRules} type="button">
          {t('accounts.detail.actions.rules')}
        </button>
        <button
          className="secondary-action"
          disabled={account.valuation.snapshotId === null}
          onClick={onReconcile}
          type="button"
        >
          {t('accounts.detail.actions.reconcile')}
        </button>
        <button
          className="secondary-action"
          disabled={!creatable}
          onClick={onAddTransfer}
          type="button"
        >
          {t('accounts.detail.actions.addTransfer')}
        </button>
        <button
          className="primary-action"
          disabled={!creatable}
          onClick={onAddTransaction}
          type="button"
        >
          {t('accounts.detail.actions.addTransaction')}
        </button>
      </div>
    </section>
  );
}
