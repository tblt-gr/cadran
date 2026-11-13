import { useTranslation } from 'react-i18next';
import styles from './TransactionDayList.module.css';

export function TransactionRegisterHeading({
  accounts,
  selection,
}: {
  accounts: boolean;
  selection: boolean;
}) {
  const { t } = useTranslation();
  return (
    <div
      aria-hidden="true"
      className={`${styles.registerHeading} ${selection ? styles.selectable : ''}`}
      data-accounts={accounts}
    >
      {selection ? <span className={styles.select} /> : null}
      <span className={styles.primary}>{t('transactions.fields.label')}</span>
      <span className={styles.category}>{t('transactions.fields.category')}</span>
      {accounts ? <span className={styles.account}>{t('transactions.fields.account')}</span> : null}
      <span className={styles.amount}>{t('transactions.fields.amount')}</span>
      <span className={styles.actions}>{t('budget.followUp.actions')}</span>
    </div>
  );
}
