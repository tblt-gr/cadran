import { useTranslation } from 'react-i18next';
import styles from './AccountFilters.module.css';

interface AccountFiltersProps {
  includeArchived: boolean;
  includeClosed: boolean;
  onIncludeArchivedChange: (checked: boolean) => void;
  onIncludeClosedChange: (checked: boolean) => void;
}

/** Closed and archived accounts leave the working set unless asked for. */
export function AccountFilters({
  includeArchived,
  includeClosed,
  onIncludeArchivedChange,
  onIncludeClosedChange,
}: AccountFiltersProps) {
  const { t } = useTranslation();

  return (
    <div className={styles.toolbar}>
      <label>
        <input
          checked={includeClosed}
          onChange={(event) => onIncludeClosedChange(event.target.checked)}
          type="checkbox"
        />
        <span>{t('accounts.includeClosed')}</span>
      </label>
      <label>
        <input
          checked={includeArchived}
          onChange={(event) => onIncludeArchivedChange(event.target.checked)}
          type="checkbox"
        />
        <span>{t('accounts.includeArchived')}</span>
      </label>
    </div>
  );
}
