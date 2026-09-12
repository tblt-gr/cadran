import type { AnalyticAxes, CategoryType } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import { SplitAxes } from './split-axes/SplitAxes';
import styles from './SplitEditor.module.css';

type AnalyticAxis = AnalyticAxes[number];

export interface SplitRowValues {
  categoryId: string;
  categoryColor?: string | null;
  categoryIcon?: string | null;
  categoryLabel?: string | null;
  /** Type of the picked category, `null` or absent while unknown. */
  categoryType?: CategoryType | null;
  /** Default axes of the picked category, which a `null` `analyticAxes` inherits. */
  defaultAxes?: AnalyticAxis[] | null;
  amount: string;
  /** `null` inherits the picked category's default axes; an explicit array, even empty, overrides them. */
  analyticAxes: AnalyticAxis[] | null;
  /** Stable identity for React reconciliation only — never sent to the API. */
  key: string;
  note: string;
}

interface SplitRowProps {
  /** Message for a category the transaction sign would refuse. */
  categoryError: string | null;
  index: number;
  invalid: boolean;
  duplicateCategory: boolean;
  onChange: (values: SplitRowValues) => void;
  onRemove: () => void;
  preferredType: CategoryType;
  removable: boolean;
  values: SplitRowValues;
}

/** One row of a transaction's split allocation: its category, exact amount, analytic axes and note. */
export function SplitRow({
  categoryError,
  index,
  invalid,
  duplicateCategory,
  onChange,
  onRemove,
  preferredType,
  removable,
  values,
}: SplitRowProps) {
  const { t } = useTranslation();

  return (
    <div className={styles.row}>
      <div className={styles.rowFields}>
        <CategoryPicker
          error={categoryError}
          label={t('transactions.split.categoryRow', { row: index + 1 })}
          labelHidden
          onChange={(categoryId, category) =>
            onChange({
              ...values,
              // A new category brings its own defaults; an override made for the
              // previous one no longer describes this row.
              analyticAxes: categoryId === values.categoryId ? values.analyticAxes : null,
              categoryColor: category?.color ?? null,
              categoryIcon: category?.icon ?? null,
              categoryId,
              categoryLabel: category?.label ?? null,
              categoryType: category?.type ?? null,
              defaultAxes: category?.defaultAnalyticAxes ?? null,
            })
          }
          placeholder={t('transactions.fields.category')}
          preferredType={preferredType}
          selectedColor={values.categoryColor}
          selectedIcon={values.categoryIcon}
          selectedLabel={values.categoryLabel}
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
            placeholder={t('transactions.fields.amount')}
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

      <SplitAxes
        axes={values.analyticAxes}
        defaultAxes={values.defaultAxes ?? null}
        index={index}
        onChange={(analyticAxes) => onChange({ ...values, analyticAxes })}
      />

      {duplicateCategory ? (
        <p className={styles.rowError} role="alert">
          {t('transactions.split.duplicateCategory')}
        </p>
      ) : null}
    </div>
  );
}
