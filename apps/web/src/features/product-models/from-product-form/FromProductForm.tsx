import type {
  AccountValuationMode,
  CreateProductModelFromProductRequest,
} from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FieldRow } from '@/features/product-models/field-row/FieldRow';
import type { ProductModelErrorKind } from '@/features/product-models/productModelError';
import { useCatalogProducts } from '@/features/product-models/useCatalogProducts';
import {
  VALUATION_MODES,
  valuationRequiredCapability,
} from '@/features/product-models/model-form/modelFormValues';
import { todayInBrowser } from '@/lib/businessDay';
import styles from './FromProductForm.module.css';

interface FromProductFormProps {
  pending: boolean;
  submitError: ProductModelErrorKind | null;
  onCancel: () => void;
  onSubmit: (body: CreateProductModelFromProductRequest) => void;
}

/**
 * Starts a model from a system catalogue product. The server copies the row
 * itself, so nothing about the product is sent back beyond its code: a model
 * that claims to come from a Livret A carries what the catalogue says a Livret
 * A is, then stops tracking it.
 */
export function FromProductForm({
  pending,
  submitError,
  onCancel: _onCancel,
  onSubmit,
}: FromProductFormProps) {
  const { t } = useTranslation();
  const [asOf] = useState(todayInBrowser);
  const products = useCatalogProducts(asOf);
  const [name, setName] = useState('');
  const [productCode, setProductCode] = useState('');
  const [valuationMode, setValuationMode] = useState<AccountValuationMode>('TRANSACTIONS');
  const [showErrors, setShowErrors] = useState(false);

  const selected = products.items.find((product) => product.code === productCode) ?? null;
  const cleanName = name.trim();
  const nameInvalid = cleanName === '' || [...cleanName].length > 80;
  const productInvalid = productCode === '';

  function feedsMode(mode: AccountValuationMode): boolean {
    return selected === null || selected.capabilities.includes(valuationRequiredCapability(mode));
  }

  /**
   * The mode a freshly chosen product starts on: the first one its capabilities
   * actually feed, so the select never shows a disabled option as its own
   * value. A catalogue product always feeds at least one mode; falling back to
   * the first entry keeps the field defined if one ever does not, and the
   * server refuses that model rather than the form pretending otherwise.
   */
  function firstModeFedBy(code: string): AccountValuationMode {
    const product = products.items.find((entry) => entry.code === code) ?? null;

    return (
      VALUATION_MODES.find(
        (mode) =>
          product === null || product.capabilities.includes(valuationRequiredCapability(mode)),
      ) ?? VALUATION_MODES[0]
    );
  }

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (nameInvalid || productInvalid) {
      setShowErrors(true);

      return;
    }

    onSubmit({ name: cleanName, productCode, valuationMode });
  }

  if (products.isPending) {
    return (
      <p aria-busy="true" className={styles.state} role="status">
        {t('productModels.fromProduct.loading')}
      </p>
    );
  }

  if (products.isError) {
    return (
      <div className={styles.state} role="alert">
        <p>{t('productModels.fromProduct.error')}</p>
        <button className="secondary-action" onClick={products.refetch} type="button">
          {t('foundation.retry')}
        </button>
      </div>
    );
  }

  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`productModels.errors.${submitError}`)}
        </p>
      ) : null}

      <FieldRow
        error={showErrors && nameInvalid ? t('productModels.form.errors.name') : undefined}
        label={t('productModels.form.name')}
      >
        {({ fieldId, describedBy }) => (
          <input
            aria-describedby={describedBy}
            aria-invalid={showErrors && nameInvalid ? true : undefined}
            id={fieldId}
            maxLength={80}
            onChange={(event) => setName(event.target.value)}
            value={name}
          />
        )}
      </FieldRow>

      <FieldRow
        error={
          showErrors && productInvalid ? t('productModels.fromProduct.errors.product') : undefined
        }
        hint={t('productModels.fromProduct.productHint')}
        label={t('productModels.fromProduct.product')}
      >
        {({ fieldId, describedBy }) => (
          <select
            aria-describedby={describedBy}
            aria-invalid={showErrors && productInvalid ? true : undefined}
            id={fieldId}
            onChange={(event) => {
              setProductCode(event.target.value);
              setValuationMode(firstModeFedBy(event.target.value));
            }}
            value={productCode}
          >
            <option value="">{t('productModels.fromProduct.chooseProduct')}</option>
            {products.items.map((product) => (
              <option key={product.code} value={product.code}>
                {product.displayName}
              </option>
            ))}
          </select>
        )}
      </FieldRow>

      <FieldRow label={t('productModels.form.valuationMode')}>
        {({ fieldId }) => (
          <select
            id={fieldId}
            onChange={(event) => setValuationMode(event.target.value as AccountValuationMode)}
            value={valuationMode}
          >
            {VALUATION_MODES.map((mode) => (
              <option disabled={!feedsMode(mode)} key={mode} value={mode}>
                {t(`accounts.valuationModes.${mode}`)}
              </option>
            ))}
          </select>
        )}
      </FieldRow>

      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'productModels.form.saving' : 'productModels.fromProduct.confirm')}
        </button>
      </div>
    </form>
  );
}
