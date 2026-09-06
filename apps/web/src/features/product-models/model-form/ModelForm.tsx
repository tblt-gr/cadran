import type {
  AccountKind,
  AccountValuationMode,
  CreateProductModelRequest,
  ProductCapability,
  ProductWrapperKind,
  ProductYieldKind,
} from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FieldRow } from '@/features/product-models/field-row/FieldRow';
import type { ProductModelErrorKind } from '@/features/product-models/productModelError';
import { useCatalogProducts } from '@/features/product-models/useCatalogProducts';
import { todayInBrowser } from '@/lib/businessDay';
import { CapabilitySelect } from './CapabilitySelect';
import {
  ACCOUNT_KINDS,
  catalogGroupCodes,
  emptyModelFormValues,
  missingCapabilityDependencies,
  modeAcceptsFamily,
  modelFormProblems,
  normalizeCapabilities,
  toCreateRequest,
  VALUATION_MODES,
  WRAPPER_KINDS,
  YIELD_KINDS,
  type ModelFormValues,
} from './modelFormValues';
import styles from './ModelForm.module.css';

interface ModelFormProps {
  pending: boolean;
  submitError: ProductModelErrorKind | null;
  onCancel: () => void;
  onSubmit: (body: CreateProductModelRequest) => void;
}

/**
 * Describing a workspace product model: its classification and the behaviours
 * it activates. Its dated periods — ceilings, rates, methods — are recorded
 * afterwards through their own endpoint, so this form never duplicates the
 * period editor and a model with no period yet is a valid starting point.
 */
export function ModelForm({ pending, submitError, onSubmit }: ModelFormProps) {
  const { t } = useTranslation();
  const [asOf] = useState(todayInBrowser);
  const products = useCatalogProducts(asOf);
  const [values, setValues] = useState<ModelFormValues>(emptyModelFormValues);
  const [showErrors, setShowErrors] = useState(false);

  const problems = showErrors ? modelFormProblems(values) : [];
  const has = (field: string) => problems.includes(field);
  const groupCodes = catalogGroupCodes(products.items);

  function patch(next: Partial<ModelFormValues>) {
    setValues((current) => {
      const merged = { ...current, ...next };

      return {
        ...merged,
        capabilities: normalizeCapabilities(
          merged.capabilities,
          merged.family,
          merged.valuationMode,
        ),
      };
    });
  }

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (modelFormProblems(values).length > 0) {
      setShowErrors(true);

      return;
    }

    onSubmit(toCreateRequest(values));
  }

  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`productModels.errors.${submitError}`)}
        </p>
      ) : null}

      <div className={styles.fields}>
        <FieldRow
          error={has('name') ? t('productModels.form.errors.name') : undefined}
          label={t('productModels.form.name')}
        >
          {({ fieldId, describedBy }) => (
            <input
              aria-describedby={describedBy}
              aria-invalid={has('name') ? true : undefined}
              id={fieldId}
              maxLength={80}
              onChange={(event) => patch({ name: event.target.value })}
              value={values.name}
            />
          )}
        </FieldRow>

        <FieldRow label={t('productModels.form.family')}>
          {({ fieldId }) => (
            <select
              id={fieldId}
              onChange={(event) => patch({ family: event.target.value as AccountKind })}
              value={values.family}
            >
              {ACCOUNT_KINDS.map((kind) => (
                <option key={kind} value={kind}>
                  {t(`catalog.accountKinds.${kind}`)}
                </option>
              ))}
            </select>
          )}
        </FieldRow>

        <FieldRow label={t('productModels.form.wrapperKind')}>
          {({ fieldId }) => (
            <select
              id={fieldId}
              onChange={(event) => patch({ wrapperKind: event.target.value as ProductWrapperKind })}
              value={values.wrapperKind}
            >
              {WRAPPER_KINDS.map((kind) => (
                <option key={kind} value={kind}>
                  {t(`catalog.wrapperKinds.${kind}`)}
                </option>
              ))}
            </select>
          )}
        </FieldRow>

        <FieldRow
          hint={t('productModels.form.yieldKindHint')}
          label={t('productModels.form.yieldKind')}
        >
          {({ fieldId, describedBy }) => (
            <select
              aria-describedby={describedBy}
              id={fieldId}
              onChange={(event) => patch({ yieldKind: event.target.value as ProductYieldKind })}
              value={values.yieldKind}
            >
              {YIELD_KINDS.map((kind) => (
                <option key={kind} value={kind}>
                  {t(`catalog.yieldKinds.${kind}`)}
                </option>
              ))}
            </select>
          )}
        </FieldRow>

        <FieldRow
          error={has('valuationMode') ? t('productModels.form.errors.valuationMode') : undefined}
          label={t('productModels.form.valuationMode')}
        >
          {({ fieldId, describedBy }) => (
            <select
              aria-describedby={describedBy}
              aria-invalid={has('valuationMode') ? true : undefined}
              id={fieldId}
              onChange={(event) =>
                patch({ valuationMode: event.target.value as AccountValuationMode })
              }
              value={values.valuationMode}
            >
              {VALUATION_MODES.map((mode) => (
                <option disabled={!modeAcceptsFamily(mode, values.family)} key={mode} value={mode}>
                  {t(`accounts.valuationModes.${mode}`)}
                </option>
              ))}
            </select>
          )}
        </FieldRow>

        <FieldRow
          error={
            has('defaultGroupCode') ? t('productModels.form.errors.defaultGroupCode') : undefined
          }
          hint={t('productModels.form.defaultGroupCodeHint')}
          label={t('productModels.form.defaultGroupCode')}
        >
          {({ fieldId, describedBy }) => (
            <select
              aria-busy={products.isPending || undefined}
              aria-describedby={describedBy}
              aria-invalid={has('defaultGroupCode') ? true : undefined}
              disabled={products.isPending}
              id={fieldId}
              onChange={(event) => patch({ defaultGroupCode: event.target.value })}
              value={values.defaultGroupCode}
            >
              <option value="">{t('catalog.groups.none')}</option>
              {groupCodes.map((code) => (
                <option key={code} value={code}>
                  {t(`catalog.groups.${code}`, { defaultValue: code })}
                </option>
              ))}
            </select>
          )}
        </FieldRow>
      </div>

      <CapabilitySelect
        invalid={has('capabilities')}
        isLiability={values.family === 'LIABILITY'}
        missing={showErrors ? missingCapabilityDependencies(values.capabilities) : []}
        onChange={(capabilities: ProductCapability[]) => patch({ capabilities })}
        value={values.capabilities}
        valuationMode={values.valuationMode}
      />

      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'productModels.form.saving' : 'productModels.form.save')}
        </button>
      </div>
    </form>
  );
}
