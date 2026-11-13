import type { Product } from '@cadran/api-client';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { ProductNameCell, ProductPairCell } from '@/components/ui/product-table/ProductTable';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { ProductDetailsModal } from '@/features/catalog/product-details-modal/ProductDetailsModal';
import { ProductFigures } from '@/features/catalog/product-figures/ProductFigures';

/**
 * One catalogue product as a row of the shared product table: what it is, whether its return is
 * owed to the holder, and the key figures the catalogue can vouch for on the business date. The
 * dated rules with their sources open in the details.
 *
 * The yield badge is stated by the server, never inferred from the product name: a PEA, a
 * securities account or a life-insurance contract earns what its assets earn, and this row must
 * not let that read as a promise.
 */
export function ProductRow({ product }: { product: Product }) {
  const { t } = useTranslation();
  const [detailsOpen, setDetailsOpen] = useState(false);
  const detailsButton = useRef<HTMLButtonElement>(null);
  const guaranteedYieldUnavailable =
    product.yieldGuaranteed && product.unavailableRuleKinds.includes('ANNUAL_RATE');
  const ruleCount = product.rules.length + product.unavailableRuleKinds.length;
  const archived = product.archivedAt !== null;

  return (
    <tr>
      <ProductNameCell name={product.displayName} note={product.code} />
      <ProductPairCell
        value={t(`catalog.accountKinds.${product.accountKind}`)}
        note={t(`catalog.wrapperKinds.${product.wrapperKind}`)}
      />
      <ProductPairCell
        value={t(`catalog.yieldKinds.${product.yieldKind}`)}
        note={
          <StatusBadge
            icon={product.yieldGuaranteed ? 'goals' : 'investments'}
            tone={
              guaranteedYieldUnavailable ? 'info' : product.yieldGuaranteed ? 'positive' : 'warning'
            }
          >
            {t(product.yieldGuaranteed ? 'catalog.yield.guaranteed' : 'catalog.yield.market')}
          </StatusBadge>
        }
      />
      <td>
        <ProductFigures product={product} />
      </td>
      <td>{ruleCount}</td>
      <td>
        <StatusBadge tone={archived ? 'warning' : 'positive'}>
          {t(archived ? 'catalog.status.archived' : 'catalog.status.active')}
        </StatusBadge>
      </td>
      <td>
        <InfoButton
          label={t('catalog.details.open', { product: product.displayName })}
          onClick={() => setDetailsOpen(true)}
          ref={detailsButton}
        />
        {detailsOpen ? (
          <ProductDetailsModal
            close={() => setDetailsOpen(false)}
            product={product}
            returnFocus={detailsButton}
          />
        ) : null}
      </td>
    </tr>
  );
}
