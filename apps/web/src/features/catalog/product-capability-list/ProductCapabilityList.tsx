import type { ProductCapability } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from './ProductCapabilityList.module.css';

interface ProductCapabilityListProps {
  capabilities: ProductCapability[];
  productCode: string;
}

/** The server-declared behavior of one product, never inferred from its name. */
export function ProductCapabilityList({ capabilities, productCode }: ProductCapabilityListProps) {
  const { t } = useTranslation();
  const titleId = `product-${productCode}-capabilities`;

  return (
    <section aria-labelledby={titleId} className={styles.section}>
      <h3 id={titleId}>{t('catalog.capabilities.title')}</h3>
      <ul>
        {capabilities.map((capability) => (
          <li key={capability}>{t(`catalog.capabilities.items.${capability}`)}</li>
        ))}
      </ul>
    </section>
  );
}
