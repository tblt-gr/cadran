import { useTranslation } from 'react-i18next';
import { ProductTablePanel } from '@/components/ui/product-table/ProductTable';
import { SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { formatCalendarDay } from '@/lib/decimal';
import { productCatalogHeadings } from './product-catalog-table/productCatalogHeadings';
import styles from './ProductCatalogPage.module.css';
import skeletonStyles from './ProductCatalogPageSkeleton.module.css';

/** Mirrors the loaded catalogue element for element: the resolution date is known, products pending. */
export function ProductCatalogPageSkeleton({ asOf, status }: { asOf: string; status: string }) {
  const { i18n, t } = useTranslation();
  return (
    <div className={skeletonStyles.contents}>
      <p className={styles.resolved}>
        {t('catalog.resolvedOn', { date: formatCalendarDay(asOf, i18n.language) })}
      </p>
      <ProductTablePanel
        busy
        caption={t('catalog.table.caption')}
        headings={productCatalogHeadings(t)}
        status={status}
        testId="product-catalog-skeleton"
      >
        <SkeletonTableRows columns={7} rows={5} />
      </ProductTablePanel>
    </div>
  );
}
