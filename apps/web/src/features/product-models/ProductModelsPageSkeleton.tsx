import { SkeletonTableRows } from '@/components/ui/skeleton/Skeleton';
import { ProductTablePanel } from '@/components/ui/product-table/ProductTable';
import { useTranslation } from 'react-i18next';

/** Mirrors ProductModelList element for element: only the rows are pending. */
export function ProductModelsPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <ProductTablePanel
      busy
      caption={t('productModels.list.caption')}
      headings={[
        { label: t('productModels.list.name') },
        { label: t('productModels.list.family') },
        { label: t('productModels.list.yield') },
        { label: t('productModels.list.periods') },
        { label: t('productModels.list.status') },
        { label: t('productModels.list.actions'), hidden: true },
      ]}
      status={status}
      testId="product-models-skeleton"
    >
      <SkeletonTableRows columns={6} rows={5} />
    </ProductTablePanel>
  );
}
