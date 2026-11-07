import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { ExplainInfo } from './ExplainInfo';
import styles from './NotComputable.module.css';

/**
 * A figure that cannot be calculated: the shared dash, then an "i" opening the sentence that
 * says why. The long sentence never sits in a cell.
 */
export function NotComputable({
  figure,
  reason,
  className,
}: {
  /** Figure label, with the row name when several rows share a column. */
  figure: string;
  reason: string;
  className?: string;
}) {
  const { t } = useTranslation();

  return (
    <span className={className ? `${styles.cell} ${className}` : styles.cell}>
      <EmptyValue label={t('investments.table.notCalculable')} />
      <ExplainInfo
        buttonLabel={t('investments.notComputable.explain', { label: figure })}
        text={reason}
        title={figure}
      />
    </span>
  );
}
