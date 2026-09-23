import type { AnalyticAxes, Category, CategoryType } from '@cadran/api-client';
import { useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import { QuickCategoryDialog } from '@/features/categories/quick-category/QuickCategoryDialog';

/** The category already on the edited transaction, with what its pill needs. */
export interface SavedCategory {
  color: string | null;
  icon: string | null;
  id: string;
  label: string;
}

interface TransactionCategoryFieldProps {
  /** Validation message, typically a category the transaction sign would refuse. */
  error: string | null;
  onChange: (
    categoryId: string,
    type: CategoryType | null,
    defaultAxes: AnalyticAxes | null,
  ) => void;
  /** Category type the draft sign expects: listed first, and the quick-create default. */
  preferredType: CategoryType;
  /** Category of the edited transaction, displayed while it stays selected. */
  savedCategory: SavedCategory | null;
  value: string;
}

/**
 * The category of a transaction draft, with quick creation: the new category is created
 * in a second-level dialog and selected without the draft behind it ever remounting.
 *
 * Both types are offered, so changing the nature or the sign never hides a category
 * the user is looking for; a pairing the sign contradicts is reported by the form.
 */
export function TransactionCategoryField({
  error,
  onChange,
  preferredType,
  savedCategory,
  value,
}: TransactionCategoryFieldProps) {
  const { t } = useTranslation();
  const picker = useRef<HTMLInputElement>(null);
  const [quickLabel, setQuickLabel] = useState<string | null>(null);
  const [created, setCreated] = useState<Category | null>(null);

  const selected =
    value === created?.id ? created : value === savedCategory?.id ? savedCategory : undefined;

  function selectCreated(category: Category) {
    setCreated(category);
    onChange(category.id, category.type, category.defaultAnalyticAxes);
  }

  return (
    <div>
      <CategoryPicker
        emptyOptionLabel={t('transactions.form.noCategory')}
        error={error}
        label={t('transactions.fields.category')}
        onChange={(categoryId, category) =>
          onChange(categoryId, category?.type ?? null, category?.defaultAnalyticAxes ?? null)
        }
        onCreateRequest={setQuickLabel}
        preferredType={preferredType}
        ref={picker}
        selectedColor={selected?.color}
        selectedIcon={selected?.icon}
        selectedLabel={selected?.label}
        value={value}
      />

      {quickLabel === null ? null : (
        <QuickCategoryDialog
          close={() => setQuickLabel(null)}
          initialLabel={quickLabel}
          initialType={preferredType}
          onCreated={selectCreated}
          returnFocus={picker}
        />
      )}
    </div>
  );
}
