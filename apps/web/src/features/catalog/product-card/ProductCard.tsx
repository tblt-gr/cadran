import type { Product } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { ProductCapabilityList } from '@/features/catalog/product-capability-list/ProductCapabilityList';
import { ProductRuleList } from '@/features/catalog/product-rule-list/ProductRuleList';
import styles from './ProductCard.module.css';

interface ProductCardProps {
  product: Product;
}

/**
 * One catalogue product: what it is, whether its return is owed to the holder,
 * and the rules that applied on the business date.
 *
 * The yield badge is stated by the server, never inferred from the product
 * name: a PEA, a securities account or a life-insurance contract earns what its
 * assets earn, and this card must not let that read as a promise.
 */
export function ProductCard({ product }: ProductCardProps) {
  const { t } = useTranslation();
  const guaranteedYieldUnavailable =
    product.yieldGuaranteed && product.unavailableRuleKinds.includes('ANNUAL_RATE');

  return (
    <article aria-labelledby={`product-${product.code}`} className={`card ${styles.card}`}>
      <header className={styles.header}>
        <div>
          <h2 id={`product-${product.code}`}>{product.displayName}</h2>
          <p className={styles.code}>{product.code}</p>
        </div>
        <div className={styles.badges}>
          <StatusBadge icon="accounts" tone="info">
            {t(`catalog.accountKinds.${product.accountKind}`)}
          </StatusBadge>
          <StatusBadge
            icon={product.yieldGuaranteed ? 'goals' : 'investments'}
            tone={
              guaranteedYieldUnavailable ? 'info' : product.yieldGuaranteed ? 'positive' : 'warning'
            }
          >
            {t(product.yieldGuaranteed ? 'catalog.yield.guaranteed' : 'catalog.yield.market')}
          </StatusBadge>
        </div>
      </header>

      <dl className={styles.classification}>
        <div>
          <dt>{t('catalog.fields.wrapper')}</dt>
          <dd>{t(`catalog.wrapperKinds.${product.wrapperKind}`)}</dd>
        </div>
        <div>
          <dt>{t('catalog.fields.yield')}</dt>
          <dd>{t(`catalog.yieldKinds.${product.yieldKind}`)}</dd>
        </div>
        <div>
          <dt>{t('catalog.fields.catalogVersion')}</dt>
          <dd>{product.catalogVersion}</dd>
        </div>
      </dl>

      <ProductCapabilityList capabilities={product.capabilities} productCode={product.code} />

      <ProductRuleList product={product} />
    </article>
  );
}
