import type { Product } from '@cadran/api-client';
import type { RefObject } from 'react';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { ProductCapabilityList } from '@/features/catalog/product-capability-list/ProductCapabilityList';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './ProductDetailsModal.module.css';

interface ProductDetailsModalProps {
  close: () => void;
  product: Product;
  returnFocus: RefObject<HTMLElement | null>;
}

/** The classification and declared capabilities of one product, out of the card's first read. */
export function ProductDetailsModal({ close, product, returnFocus }: ProductDetailsModalProps) {
  const { i18n, t } = useTranslation();
  const jurisdiction = formatJurisdiction(product.jurisdiction, i18n.language);
  const archivedDay = product.archivedAt?.slice(0, 10) ?? null;

  return (
    <Modal
      close={close}
      eyebrow={product.code}
      returnFocus={returnFocus}
      title={product.displayName}
    >
      <dl className={styles.classification}>
        <div>
          <dt>{t('catalog.fields.accountKind')}</dt>
          <dd>{t(`catalog.accountKinds.${product.accountKind}`)}</dd>
        </div>
        <div>
          <dt>{t('catalog.fields.jurisdiction')}</dt>
          <dd>{jurisdiction ?? t('catalog.jurisdictions.none')}</dd>
        </div>
        <div>
          <dt>{t('catalog.fields.wrapper')}</dt>
          <dd>{t(`catalog.wrapperKinds.${product.wrapperKind}`)}</dd>
        </div>
        <div>
          <dt>{t('catalog.fields.yield')}</dt>
          <dd>{t(`catalog.yieldKinds.${product.yieldKind}`)}</dd>
        </div>
        <div>
          <dt>{t('catalog.fields.ceilingBasis')}</dt>
          <dd>{t(`catalog.ceilingBases.${product.ceilingBasis}`)}</dd>
        </div>
        <div>
          <dt>{t('catalog.fields.group')}</dt>
          <dd>
            {product.defaultGroupCode === null
              ? t('catalog.groups.none')
              : t(`catalog.groups.${product.defaultGroupCode}`, {
                  defaultValue: product.defaultGroupCode,
                })}
          </dd>
        </div>
        <div>
          <dt>{t('catalog.fields.catalogVersion')}</dt>
          <dd>{product.catalogVersion}</dd>
        </div>
        {archivedDay !== null ? (
          <div>
            <dt>{t('catalog.fields.status')}</dt>
            <dd>
              {t('catalog.status.archivedOn', {
                date: formatCalendarDay(archivedDay, i18n.language),
              })}
            </dd>
          </div>
        ) : null}
      </dl>
      <ProductCapabilityList capabilities={product.capabilities} productCode={product.code} />
    </Modal>
  );
}

function formatJurisdiction(code: string | null, locale: string): string | null {
  if (code === null) {
    return null;
  }

  try {
    return new Intl.DisplayNames(locale, { type: 'region' }).of(code) ?? code;
  } catch {
    return code;
  }
}
