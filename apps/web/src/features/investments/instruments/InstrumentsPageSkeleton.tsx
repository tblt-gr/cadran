import type { ParseKeys } from 'i18next';
import { useTranslation } from 'react-i18next';
import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import styles from './instrument-table/InstrumentTable.module.css';

/** Mirrors InstrumentTable element for element: header and caption are real, rows pending. */
export function InstrumentsPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  const headers = ['name', 'isin', 'ticker', 'type', 'class', 'region', 'currency', 'status'];
  return (
    <div aria-busy="true" className={`card ${styles.panel}`} data-testid="instruments-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.scroll}>
        <table>
          <caption className="sr-only">{t('investments.tabs.instruments')}</caption>
          <thead>
            <tr>
              {[...headers, ''].map((key) => (
                <th key={key} scope="col">
                  {key ? t(`investments.instruments.table.${key}` as ParseKeys) : ''}
                </th>
              ))}
            </tr>
          </thead>
          <SkeletonTableRows columns={9} rows={8} />
        </table>
      </div>
    </div>
  );
}
