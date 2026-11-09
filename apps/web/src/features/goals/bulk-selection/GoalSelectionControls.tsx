import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { useGoalSelection } from './goalSelectionContext';
import styles from './GoalSelection.module.css';

export function GoalSelectionToggle() {
  const { t } = useTranslation();
  const selection = useGoalSelection();
  if (!selection) return null;
  return (
    <button
      aria-label={t('goals.bulk.enter')}
      aria-pressed={selection.active}
      className={`icon-ghost ${styles.pen}`}
      disabled={!selection.ready}
      onClick={selection.toggleActive}
      title={t('goals.bulk.enter')}
      type="button"
    >
      <Icon name="edit" size={18} />
    </button>
  );
}

export function GoalSelectionToolbar() {
  const { t } = useTranslation();
  const selection = useGoalSelection();
  if (!selection?.active) return null;
  return (
    <div aria-label={t('goals.bulk.toolbar')} className={styles.toolbar} role="group">
      <label className={styles.selectAll}>
        <input
          checked={selection.allSelected}
          onChange={selection.toggleAll}
          ref={(element) => {
            if (element) element.indeterminate = selection.someSelected && !selection.allSelected;
          }}
          type="checkbox"
        />
        <span className="sr-only">{t('goals.bulk.selectAll')}</span>
      </label>
      <p aria-live="polite" className={styles.counter} role="status">
        {t('goals.bulk.count', { count: selection.selected.size })}
      </p>
      <button
        className={styles.action}
        disabled={selection.selected.size === 0}
        onClick={selection.stop}
        type="button"
      >
        {t('goals.bulk.stop')}
      </button>
      <button
        className={styles.action}
        disabled={selection.selected.size === 0}
        onClick={selection.remove}
        type="button"
      >
        {t('goals.bulk.delete')}
      </button>
    </div>
  );
}
