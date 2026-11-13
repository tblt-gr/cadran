import type { TFunction } from 'i18next';
import type { ProductTableHeading } from '@/components/ui/product-table/ProductTable';

/** The columns of the catalogue table, shared by the loaded table and its skeleton. */
export function productCatalogHeadings(t: TFunction): ProductTableHeading[] {
  return [
    { label: t('catalog.table.name') },
    { label: t('catalog.table.family') },
    { label: t('catalog.table.yield') },
    { label: t('catalog.table.figures') },
    { label: t('catalog.table.rules') },
    { label: t('catalog.table.status') },
    { label: t('catalog.table.details'), hidden: true },
  ];
}
