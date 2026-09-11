import { useTranslation } from 'react-i18next';
import styles from './CategorizationTabs.module.css';

export type Categorization = 'ALL' | 'NONE';

interface CategorizationTabsProps {
  onChange: (value: Categorization) => void;
  value: Categorization;
}

/**
 * Switches the transactions list between every movement and the "to
 * categorise" queue. A plain named toggle group rather than the ARIA tabs
 * pattern: there is no associated tabpanel to wire up, and a group of
 * pressed buttons carries the same meaning without promising roving
 * keyboard navigation it does not implement. The selected entry is never
 * marked by colour alone — it also carries more weight and a check mark.
 */
export function CategorizationTabs({ onChange, value }: CategorizationTabsProps) {
  const { t } = useTranslation();

  return (
    <div
      aria-label={t('transactions.filters.categorizationLabel')}
      className={styles.tabs}
      role="group"
    >
      {(['ALL', 'NONE'] as const).map((candidate) => (
        <button
          aria-pressed={value === candidate}
          className={`secondary-action ${styles.tab}`}
          key={candidate}
          onClick={() => onChange(candidate)}
          type="button"
        >
          {value === candidate ? <span aria-hidden="true">✓ </span> : null}
          {t(
            candidate === 'ALL'
              ? 'transactions.filters.categorizationAll'
              : 'transactions.filters.categorizationQueue',
          )}
        </button>
      ))}
    </div>
  );
}
