import type { Category, CategoryType, PaymentMethod } from '@cadran/api-client';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import styles from './RecurrenceForm.module.css';

const PAYMENT_METHODS: PaymentMethod[] = [
  'CARD',
  'TRANSFER',
  'DIRECT_DEBIT',
  'CHECK',
  'CASH',
  'OTHER',
];
const MAX_NOTE_LENGTH = 500;

interface RecurrenceTransactionFieldsProps {
  autoCreate: boolean;
  categoryError: boolean;
  categoryId: string;
  /** Type the amount direction accepts; every type when the direction is not known. */
  categoryType: CategoryType | undefined;
  note: string;
  onCategoryChange: (categoryId: string, category: Category | null) => void;
  onChange: {
    (field: 'autoCreate', value: boolean): void;
    (field: 'note', value: string): void;
    (field: 'paymentMethod', value: PaymentMethod | ''): void;
  };
  paymentMethod: PaymentMethod | '';
  /** What the picker draws for a category known only by its identifier. */
  selected: { color?: string | null; icon?: string | null; label?: string | null };
}

/**
 * What every generated transaction inherits, and the switch that makes the recurrence create
 * them. A fragment, so the fields stay cells of the form grid.
 */
export function RecurrenceTransactionFields({
  autoCreate,
  categoryError,
  categoryId,
  categoryType,
  note,
  onCategoryChange,
  onChange,
  paymentMethod,
  selected,
}: RecurrenceTransactionFieldsProps) {
  const { t } = useTranslation();
  const hintId = useId();

  return (
    <>
      <CategoryPicker
        emptyOptionLabel={t('recurrences.form.noCategory')}
        error={categoryError ? t('recurrences.validation.category') : null}
        key={categoryType ?? 'ALL'}
        label={t('recurrences.fields.category')}
        onChange={onCategoryChange}
        preferredType={categoryType}
        selectedColor={selected.color}
        selectedIcon={selected.icon}
        selectedLabel={selected.label}
        type={categoryType}
        value={categoryId}
      />
      <label>
        <span>{t('recurrences.fields.paymentMethod')}</span>
        <select
          onChange={(event) => onChange('paymentMethod', event.target.value as PaymentMethod | '')}
          value={paymentMethod}
        >
          <option value="">{t('recurrences.form.noPaymentMethod')}</option>
          {PAYMENT_METHODS.map((method) => (
            <option key={method} value={method}>
              {t(`transactions.paymentMethods.${method}`)}
            </option>
          ))}
        </select>
      </label>
      <label className={styles.wide}>
        <span>{t('recurrences.fields.note')}</span>
        <input
          maxLength={MAX_NOTE_LENGTH}
          onChange={(event) => onChange('note', event.target.value)}
          value={note}
        />
      </label>
      <div className={`${styles.wide} ${styles.autoCreate}`}>
        <label>
          <input
            aria-describedby={hintId}
            checked={autoCreate}
            onChange={(event) => onChange('autoCreate', event.target.checked)}
            type="checkbox"
          />
          <span>{t('recurrences.fields.autoCreate')}</span>
        </label>
        <small id={hintId}>{t('recurrences.form.autoCreateHint')}</small>
      </div>
    </>
  );
}
