import type { ProductModel } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { FieldRow } from '@/features/product-models/field-row/FieldRow';
import type { ProductModelErrorKind } from '@/features/product-models/productModelError';
import styles from './DuplicateModelForm.module.css';

interface DuplicateModelFormProps {
  model: ProductModel;
  pending: boolean;
  submitError: ProductModelErrorKind | null;
  onCancel: () => void;
  onSubmit: (name: string) => void;
}

/**
 * Copies a model under a new name. The copy takes the description and the dated
 * periods with their effective dates untouched, and nothing else — no account,
 * balance or external identifier ever belonged to a model. It records the model
 * it started from and stops following it.
 */
export function DuplicateModelForm({
  model,
  pending,
  submitError,
  onCancel,
  onSubmit,
}: DuplicateModelFormProps) {
  const { t } = useTranslation();
  const [name, setName] = useState(() =>
    t('productModels.duplicate.defaultName', { name: model.name }),
  );
  const [showErrors, setShowErrors] = useState(false);

  const cleanName = name.trim();
  const nameInvalid = cleanName === '' || [...cleanName].length > 80;

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (nameInvalid) {
      setShowErrors(true);

      return;
    }

    onSubmit(cleanName);
  }

  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`productModels.errors.${submitError}`)}
        </p>
      ) : null}

      <p className={styles.hint}>
        {t('productModels.duplicate.description', { name: model.name })}
      </p>

      <FieldRow
        error={showErrors && nameInvalid ? t('productModels.form.errors.name') : undefined}
        label={t('productModels.duplicate.nameLabel')}
      >
        {({ fieldId, describedBy }) => (
          <input
            aria-describedby={describedBy}
            aria-invalid={showErrors && nameInvalid ? true : undefined}
            data-autofocus
            id={fieldId}
            maxLength={80}
            onChange={(event) => setName(event.target.value)}
            value={name}
          />
        )}
      </FieldRow>

      <div className={styles.actions}>
        <button className="secondary-action" onClick={onCancel} type="button">
          {t('productModels.form.cancel')}
        </button>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'productModels.duplicate.confirming' : 'productModels.duplicate.confirm')}
        </button>
      </div>
    </form>
  );
}
