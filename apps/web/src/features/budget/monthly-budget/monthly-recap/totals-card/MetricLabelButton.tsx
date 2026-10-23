import { useTranslation } from 'react-i18next';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import styles from './TotalsCard.module.css';

export function MetricLabelButton({
  label,
  disabled = false,
  onClick,
}: {
  label: string | null;
  disabled?: boolean;
  onClick?: () => void;
}) {
  const { t } = useTranslation();
  return (
    <button
      aria-haspopup="dialog"
      aria-label={label === null ? undefined : t('reports.explainKpi', { kpi: label })}
      className={styles.label}
      disabled={disabled}
      onClick={onClick}
      type="button"
    >
      {label ?? <SkeletonBlock className={styles.labelSkeleton} />}
    </button>
  );
}
