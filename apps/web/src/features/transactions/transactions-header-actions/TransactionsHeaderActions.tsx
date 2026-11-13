import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import styles from '@/features/transactions/TransactionsPage.module.css';

export function TransactionsHeaderActions({
  onCreate,
  onTransfer,
}: {
  onCreate: () => void;
  onTransfer: () => void;
}) {
  const { t } = useTranslation();
  return (
    <HeaderActions>
      <button
        aria-label={t('transactions.add')}
        className={`primary-action ${styles.add}`}
        onClick={onCreate}
        type="button"
      >
        <Icon name="add" size={18} />
        <span className={styles.addLabel}>{t('transactions.add')}</span>
      </button>
      <button
        aria-label={t('transactions.addTransfer')}
        className="icon-button"
        onClick={onTransfer}
        title={t('transactions.addTransfer')}
        type="button"
      >
        <Icon name="transactions" size={18} />
      </button>
    </HeaderActions>
  );
}
