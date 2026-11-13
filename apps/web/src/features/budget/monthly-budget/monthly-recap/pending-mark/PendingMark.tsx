import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './PendingMark.module.css';

/** The small orange clock of a recap that pending operations can still change; absent otherwise. */
export function PendingMark({ pending }: { pending: boolean }) {
  const { t } = useTranslation();
  if (!pending) return null;
  const label = t('budget.monthly.recap.pending');
  return (
    <span aria-label={label} className={styles.mark} role="img" title={label}>
      <Icon name="clock" size={14} />
    </span>
  );
}
