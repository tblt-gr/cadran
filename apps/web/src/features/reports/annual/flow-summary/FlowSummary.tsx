import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './FlowSummary.module.css';

interface FlowSummaryProps {
  income: ReactNode | null;
  expenses: ReactNode | null;
}

/**
 * The two figures a folded mobile card keeps visible: income in green, expenses in red. The hidden
 * labels carry the meaning that the colour alone must not.
 */
export function FlowSummary({ income, expenses }: FlowSummaryProps) {
  const { t } = useTranslation();
  return (
    <span className={styles.summary}>
      {income === null ? null : (
        <span className={styles.income}>
          <span className="sr-only">{t('reports.annual.flowSummary.income')} </span>
          {income}
        </span>
      )}
      {expenses === null ? null : (
        <span className={styles.expenses}>
          <span className="sr-only">{t('reports.annual.flowSummary.expenses')} </span>
          {expenses}
        </span>
      )}
    </span>
  );
}
