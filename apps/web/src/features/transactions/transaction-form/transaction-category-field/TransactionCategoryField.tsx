import type { Category, CategoryType } from '@cadran/api-client';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import { QuickCategoryDialog } from '@/features/categories/quick-category/QuickCategoryDialog';
import styles from './TransactionCategoryField.module.css';

interface TransactionCategoryFieldProps {
  onChange: (categoryId: string) => void;
  /** Category of the edited transaction, displayed while it stays selected. */
  savedCategory: { id: string; label: string } | null;
  /** Category type the draft accepts, derived from its sign. */
  type: CategoryType;
  value: string;
}

/**
 * The category of a transaction draft, with quick creation: the new category is created
 * in a second-level dialog and selected without the draft behind it ever remounting.
 */
export function TransactionCategoryField({
  onChange,
  savedCategory,
  type,
  value,
}: TransactionCategoryFieldProps) {
  const { t } = useTranslation();
  const picker = useRef<HTMLInputElement>(null);
  const [quickLabel, setQuickLabel] = useState<string | null>(null);
  const [created, setCreated] = useState<Category | null>(null);
  const [notice, setNotice] = useState<string | null>(null);

  const selectedLabel =
    value === created?.id
      ? created.label
      : value === savedCategory?.id
        ? savedCategory.label
        : undefined;

  function selectCreated(category: Category) {
    // The quick form keeps the type editable, but the draft only accepts a category of
    // its own type: selecting another one would be refused when the transaction is saved.
    if (category.type !== type) {
      setNotice(
        t('categories.picker.createdOtherType', {
          label: category.label,
          type: t(`categories.types.${category.type}`),
        }),
      );
      return;
    }

    setCreated(category);
    onChange(category.id);
  }

  return (
    <div>
      <CategoryPicker
        emptyOptionLabel={t('transactions.form.noCategory')}
        label={t('transactions.fields.category')}
        onChange={(categoryId) => {
          setNotice(null);
          onChange(categoryId);
        }}
        onCreateRequest={(label) => {
          setNotice(null);
          setQuickLabel(label);
        }}
        ref={picker}
        selectedLabel={selectedLabel}
        type={type}
        value={value}
      />

      {/* Rendered empty beforehand so assistive technologies announce the notice. */}
      <p className={styles.notice} role="status">
        {notice}
      </p>

      {quickLabel === null ? null : (
        <QuickCategoryDialog
          close={() => setQuickLabel(null)}
          initialLabel={quickLabel}
          initialType={type}
          onCreated={selectCreated}
          returnFocus={picker}
        />
      )}
    </div>
  );
}
