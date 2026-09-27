import { useTranslation } from 'react-i18next';
import styles from './MultiYearSkeleton.module.css';

interface MultiYearSkeletonProps {
  /** Rows to draw, one per expected year of the requested (or default) range. */
  years: number;
}

/** One placeholder row per expected year, per the spec's loading-state decision. */
export function MultiYearSkeleton({ years }: MultiYearSkeletonProps) {
  const { t } = useTranslation();

  return (
    <section aria-busy="true" className={`card ${styles.state}`} role="status">
      <h2>{t('reports.loading')}</h2>
      <table className={styles.table}>
        <tbody>
          {Array.from({ length: years }, (_, index) => (
            <tr className={styles.row} key={index}>
              <td />
            </tr>
          ))}
        </tbody>
      </table>
    </section>
  );
}
