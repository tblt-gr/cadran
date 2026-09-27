import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './AnnualTableActions.module.css';

interface AnnualTableActionsProps {
  includeIncomplete: boolean;
  onOpenColumns: () => void;
  onToggleIncomplete: () => void;
  saving: boolean;
}

/** Column picker and incomplete-month switch as circular icon buttons, next to the table title. */
export function AnnualTableActions({
  includeIncomplete,
  onOpenColumns,
  onToggleIncomplete,
  saving,
}: AnnualTableActionsProps) {
  const { t } = useTranslation();
  const incompleteLabel = t('reports.annual.incompleteMonths', {
    state: t(includeIncomplete ? 'reports.annual.included' : 'reports.annual.excluded'),
  });

  return (
    <div className={styles.actions}>
      <button
        aria-label={t('reports.annual.columns')}
        className={`icon-ghost ${styles.button}`}
        onClick={onOpenColumns}
        title={t('reports.annual.columns')}
        type="button"
      >
        <Icon name="categories" size={16} />
      </button>
      <button
        aria-label={incompleteLabel}
        aria-pressed={includeIncomplete}
        className={`icon-ghost ${styles.button}`}
        disabled={saving}
        onClick={onToggleIncomplete}
        title={incompleteLabel}
        type="button"
      >
        <Icon name="calendar" size={16} />
      </button>
    </div>
  );
}
