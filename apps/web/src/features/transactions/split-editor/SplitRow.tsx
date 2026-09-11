import type { AnalyticAxes, CategoryType } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import styles from './SplitEditor.module.css';

type AnalyticAxis = AnalyticAxes[number];

const AXES: AnalyticAxis[] = [
  'ESSENTIAL',
  'DISCRETIONARY',
  'FIXED',
  'VARIABLE',
  'PERSONAL',
  'PROFESSIONAL',
];

export interface SplitRowValues {
  categoryId: string;
  categoryColor?: string | null;
  categoryIcon?: string | null;
  categoryLabel?: string | null;
  amount: string;
  /** `null` inherits the picked category's default axes; an explicit array, even empty, overrides them. */
  analyticAxes: AnalyticAxis[] | null;
  /** Stable identity for React reconciliation only — never sent to the API. */
  key: string;
  note: string;
}

interface SplitRowProps {
  index: number;
  invalid: boolean;
  duplicateCategory: boolean;
  onChange: (values: SplitRowValues) => void;
  onRemove: () => void;
  removable: boolean;
  type: CategoryType;
  values: SplitRowValues;
}

/** One row of a transaction's split allocation: its category, exact amount, analytic axes and note. */
export function SplitRow({
  index,
  invalid,
  duplicateCategory,
  onChange,
  onRemove,
  removable,
  type,
  values,
}: SplitRowProps) {
  const { t } = useTranslation();

  function toggleAxis(axis: AnalyticAxis) {
    const current = values.analyticAxes ?? [];
    onChange({
      ...values,
      analyticAxes: current.includes(axis)
        ? current.filter((candidate) => candidate !== axis)
        : [...current, axis],
    });
  }

  return (
    <div className={styles.row}>
      <div className={styles.rowFields}>
        <CategoryPicker
          label={t('transactions.split.categoryRow', { row: index + 1 })}
          onChange={(categoryId) => onChange({ ...values, categoryId })}
          selectedColor={values.categoryColor}
          selectedIcon={values.categoryIcon}
          selectedLabel={values.categoryLabel}
          type={type}
          value={values.categoryId}
        />

        <label>
          <span className="sr-only">{t('transactions.split.amountRow', { row: index + 1 })}</span>
          <input
            aria-invalid={invalid ? true : undefined}
            aria-label={t('transactions.split.amountRow', { row: index + 1 })}
            autoComplete="off"
            inputMode="decimal"
            onChange={(event) => onChange({ ...values, amount: event.target.value })}
            spellCheck={false}
            value={values.amount}
          />
        </label>

        <label>
          <span className="sr-only">{t('transactions.split.noteRow', { row: index + 1 })}</span>
          <input
            aria-label={t('transactions.split.noteRow', { row: index + 1 })}
            onChange={(event) => onChange({ ...values, note: event.target.value })}
            placeholder={t('transactions.fields.note')}
            value={values.note}
          />
        </label>

        <button
          aria-label={t('transactions.split.removeRow', { row: index + 1 })}
          className="icon-button"
          disabled={!removable}
          onClick={onRemove}
          type="button"
        >
          <Icon name="close" />
        </button>
      </div>

      <fieldset className={styles.axes}>
        <legend className="sr-only">{t('transactions.split.axesRow', { row: index + 1 })}</legend>
        {AXES.map((axis) => (
          <label key={axis}>
            <input
              checked={(values.analyticAxes ?? []).includes(axis)}
              onChange={() => toggleAxis(axis)}
              type="checkbox"
            />
            <span>{t(`categories.axes.${axis}`)}</span>
          </label>
        ))}
      </fieldset>

      {duplicateCategory ? (
        <p className={styles.rowError} role="alert">
          {t('transactions.split.duplicateCategory')}
        </p>
      ) : null}
    </div>
  );
}
