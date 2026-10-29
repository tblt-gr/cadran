import type { Product } from '@cadran/api-client';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Disclosure } from '@/components/ui/disclosure/Disclosure';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { ProductDetailsModal } from '@/features/catalog/product-details-modal/ProductDetailsModal';
import { ProductFigures } from '@/features/catalog/product-figures/ProductFigures';
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
  const [detailsOpen, setDetailsOpen] = useState(false);
  const [rulesOpen, setRulesOpen] = useState(false);
  const detailsButton = useRef<HTMLButtonElement>(null);
  const ruleCount = product.rules.length + product.unavailableRuleKinds.length;
  const archivedDay = product.archivedAt?.slice(0, 10) ?? null;

  return (
    <article aria-labelledby={`product-${product.code}`} className={`card ${styles.card}`}>
      <header className={styles.header}>
        <div className={styles.title}>
          <h2 id={`product-${product.code}`}>{product.displayName}</h2>
          <InfoButton
            label={t('catalog.details.open', { product: product.displayName })}
            onClick={() => setDetailsOpen(true)}
            ref={detailsButton}
          />
        </div>
        <div className={styles.badges}>
          {archivedDay !== null ? (
            <StatusBadge tone="warning">{t('catalog.status.archived')}</StatusBadge>
          ) : null}
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

      <ProductFigures product={product} />

      <Disclosure
        meta={ruleCount}
        onToggle={setRulesOpen}
        open={rulesOpen}
        title={t('catalog.rules.disclosure')}
      >
        <ProductRuleList product={product} />
      </Disclosure>

      {detailsOpen ? (
        <ProductDetailsModal
          close={() => setDetailsOpen(false)}
          product={product}
          returnFocus={detailsButton}
        />
      ) : null}
    </article>
  );
}
