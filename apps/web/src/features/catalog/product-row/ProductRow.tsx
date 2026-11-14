import type { Product } from '@cadran/api-client';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
import { ProductNameCell, ProductPairCell } from '@/components/ui/product-table/ProductTable';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { ProductDetailsModal } from '@/features/catalog/product-details-modal/ProductDetailsModal';
import { ProductFigures } from '@/features/catalog/product-figures/ProductFigures';

/**
 * One catalogue product as a row of the shared product table: what it is, the kind of return the
 * server states for it, and the key figures reduced to a label and a value. The dates, verification
 * states and sources open in the details.
 *
 * The yield is the server's kind of return in words, never inferred from the product name and
 * never a badge: a PEA, a securities account or a life-insurance contract earns what its assets
 * earn, and this row must not let that read as a promise.
 */
export function ProductRow({ product }: { product: Product }) {
  const { t } = useTranslation();
  const [detailsOpen, setDetailsOpen] = useState(false);
  const detailsButton = useRef<HTMLButtonElement>(null);
  const ruleCount = product.rules.length + product.unavailableRuleKinds.length;
  const archived = product.archivedAt !== null;

  return (
    <tr>
      <ProductNameCell name={product.displayName} note={product.code} />
      <ProductPairCell
        value={t(`catalog.accountKinds.${product.accountKind}`)}
        note={t(`catalog.wrapperKinds.${product.wrapperKind}`)}
      />
      <td>{t(`catalog.yieldKinds.${product.yieldKind}`)}</td>
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
