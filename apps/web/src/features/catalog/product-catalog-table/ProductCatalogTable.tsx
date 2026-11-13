import type { Product } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ProductTablePanel } from '@/components/ui/product-table/ProductTable';
import { ProductRow } from '@/features/catalog/product-row/ProductRow';
import { productCatalogHeadings } from './productCatalogHeadings';

/** The system products in the table shared with the product models. */
export function ProductCatalogTable({ products }: { products: readonly Product[] }) {
  const { t } = useTranslation();
  return (
    <ProductTablePanel caption={t('catalog.table.caption')} headings={productCatalogHeadings(t)}>
      <tbody>
        {products.map((product) => (
          <ProductRow key={product.code} product={product} />
        ))}
      </tbody>
    </ProductTablePanel>
  );
}
