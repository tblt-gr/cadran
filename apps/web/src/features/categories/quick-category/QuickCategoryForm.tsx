import type { CategoryType } from '@cadran/api-client';
import { useEffect, useId, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { ParentCategoryField } from '@/features/categories/category-form/parent-category-field/ParentCategoryField';
import type { CategoryErrorKind } from '@/features/categories/categoryError';
import type { QuickCategoryInput } from './useCreateQuickCategory';
import styles from './QuickCategoryForm.module.css';

interface QuickCategoryFormProps {
  initialLabel: string;
  /** Type the draft accepts, prefilled and still editable. */
  initialType: CategoryType;
  onSubmit: (input: QuickCategoryInput) => void;
  pending: boolean;
  submitError: CategoryErrorKind | null;
}

export function QuickCategoryForm({
  initialLabel,
  initialType,
  onSubmit,
  pending,
  submitError,
}: QuickCategoryFormProps) {
  const { t } = useTranslation();
  const labelErrorId = useId();
  const typeHintId = useId();
  const [label, setLabel] = useState(initialLabel);
  const [type, setType] = useState(initialType);
  const [parentId, setParentId] = useState('');
  const [showErrors, setShowErrors] = useState(false);
  const submitted = useRef(false);
  const cleanLabel = label.trim();
  const labelInvalid = cleanLabel.length < 1 || [...cleanLabel].length > 80;
  const labelErrorShown = showErrors && labelInvalid;
  const typeDiffers = type !== initialType;

  useEffect(() => {
    if (!pending) submitted.current = false;
  }, [pending]);

  function submit(event: React.FormEvent) {
    event.preventDefault();
    // A form rendered through a portal still bubbles through its React owner.
    // Keep this nested submission away from the transaction form behind it.
    event.stopPropagation();
    if (labelInvalid) {
      setShowErrors(true);
      return;
    }
    if (submitted.current) return;

    submitted.current = true;
    onSubmit({ label: cleanLabel, type, parentId: parentId || null });
  }

  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`categories.quick.errors.${submitError}`)}
        </p>
      ) : null}

      <div className={styles.fields}>
        <label>
          <span>{t('categories.fields.label')}</span>
          <input
            aria-describedby={labelErrorShown ? labelErrorId : undefined}
            aria-invalid={labelErrorShown ? true : undefined}
            autoComplete="off"
            data-autofocus
            maxLength={80}
            onChange={(event) => setLabel(event.target.value)}
            required
            value={label}
          />
          {labelErrorShown ? (
            <small id={labelErrorId}>{t('categories.validation.label')}</small>
          ) : null}
        </label>

        <label>
          <span>{t('categories.fields.type')}</span>
          <select
            aria-describedby={typeDiffers ? typeHintId : undefined}
            onChange={(event) => {
              setType(event.target.value as CategoryType);
              setParentId('');
            }}
            value={type}
          >
            <option value="EXPENSE">{t('categories.types.EXPENSE')}</option>
            <option value="INCOME">{t('categories.types.INCOME')}</option>
          </select>
          {typeDiffers ? (
            <small className={styles.hint} id={typeHintId}>
              {t('categories.quick.typeMismatch', {
                type: t(`categories.types.${initialType}`),
              })}
            </small>
          ) : null}
        </label>

        {/* A parent only fits one type: remounting drops the picker's stale search and choice. */}
        <ParentCategoryField key={type} onChange={setParentId} type={type} value={parentId} />
      </div>

      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'categories.quick.creating' : 'categories.quick.create')}
        </button>
      </div>
    </form>
  );
}
