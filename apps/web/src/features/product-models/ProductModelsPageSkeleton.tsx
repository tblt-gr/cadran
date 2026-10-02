import { SkeletonStatus, SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { useTranslation } from 'react-i18next';
import styles from './product-model-list/ProductModelList.module.css';

export function ProductModelsPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <section
      aria-busy="true"
      className={`card ${styles.panel}`}
      data-testid="product-models-skeleton"
    >
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={styles.tableScroll}>
        <table>
          <thead>
            <tr>
              <th>{t('productModels.list.name')}</th>
              <th>{t('productModels.list.family')}</th>
              <th>{t('productModels.list.yield')}</th>
              <th>{t('productModels.list.periods')}</th>
              <th>{t('productModels.list.status')}</th>
              <th>
                <span className="sr-only">{t('productModels.list.actions')}</span>
              </th>
            </tr>
          </thead>
          <SkeletonTableRows columns={6} rows={5} />
        </table>
      </div>
    </section>
  );
}
