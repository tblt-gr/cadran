import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import styles from './category-list/CategoryList.module.css';

/** Mirrors CategoryList element for element: only the rows are pending. */
export function CategoryPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <div aria-busy="true" className={`card ${styles.panel}`} data-testid="category-page-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{t('categories.list.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('categories.fields.label')}</th>
              <th scope="col">{t('categories.fields.type')}</th>
              <th scope="col">{t('categories.fields.parent')}</th>
              <th scope="col">{t('categories.fields.axes')}</th>
              <th scope="col">{t('categories.list.redirection')}</th>
              <th scope="col">{t('categories.list.status')}</th>
              <th scope="col">
                <span className="sr-only">{t('categories.list.actions')}</span>
              </th>
            </tr>
          </thead>
          <SkeletonTableRows columns={7} rows={6} />
        </table>
      </div>
    </div>
  );
}
