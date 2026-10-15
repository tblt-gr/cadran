import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import styles from './product-model-list/ProductModelList.module.css';

/** Mirrors ProductModelList element for element: only the rows are pending. */
export function ProductModelsPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <div aria-busy="true" className={`card ${styles.panel}`} data-testid="product-models-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{t('productModels.list.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('productModels.list.name')}</th>
              <th scope="col">{t('productModels.list.family')}</th>
              <th scope="col">{t('productModels.list.yield')}</th>
              <th scope="col">{t('productModels.list.periods')}</th>
              <th scope="col">{t('productModels.list.status')}</th>
              <th scope="col">
                <span className="sr-only">{t('productModels.list.actions')}</span>
              </th>
            </tr>
          </thead>
          <SkeletonTableRows columns={6} rows={5} />
        </table>
      </div>
    </div>
  );
}
