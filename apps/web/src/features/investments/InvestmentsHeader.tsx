import { useTranslation } from 'react-i18next';
import { InvestmentsTabs } from './InvestmentsTabs';
import styles from './InvestmentsPage.module.css';

/** Description and tab strip; the title itself comes from the shell. */
export function InvestmentsHeader({ path }: { path: '/investments' | '/investments/instruments' }) {
  const { t } = useTranslation();

  return (
    <header className={styles.header}>
      <p className={styles.description}>{t('investments.description')}</p>
      <InvestmentsTabs path={path} />
    </header>
  );
}
