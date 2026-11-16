import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import {
  MAX_ESSENTIAL_CATEGORIES,
  type PolicyDraft,
} from '@/features/goals/emergency-fund/emergencyFundPolicyFields';
import styles from './EssentialCategories.module.css';

interface EssentialCategoriesProps {
  categories: PolicyDraft['categories'];
  invalid: boolean;
  onChange: (categories: PolicyDraft['categories']) => void;
}

/** The expense categories counted as essential: the chosen ones, then a picker while room remains. */
export function EssentialCategories({ categories, invalid, onChange }: EssentialCategoriesProps) {
  const { t } = useTranslation();
  const [pickerKey, setPickerKey] = useState(0);

  const addCategory = (id: string, category: { label: string } | null) => {
    if (!id || categories.some((entry) => entry.id === id)) return;
    onChange([...categories, { id, label: category?.label ?? id, archived: false }]);
    setPickerKey((key) => key + 1);
  };

  return (
    <div className={styles.categories}>
      {categories.length > 0 ? (
        <ul aria-label={t('goals.emergencyFund.chosenCategories')}>
          {categories.map((category) => (
            <li key={category.id}>
              <span>{category.label}</span>
              <button
                aria-label={t('goals.emergencyFund.removeCategory', { label: category.label })}
                className="secondary-action"
                onClick={() => onChange(categories.filter((entry) => entry.id !== category.id))}
                type="button"
              >
                {t('goals.emergencyFund.remove')}
              </button>
            </li>
          ))}
        </ul>
      ) : null}
      {categories.length < MAX_ESSENTIAL_CATEGORIES ? (
        <CategoryPicker
          error={invalid ? t('goals.emergencyFund.validation.categories') : null}
          isEligible={(category) =>
            category.budgetIncluded && !categories.some((entry) => entry.id === category.id)
          }
          key={pickerKey}
          label={t('goals.emergencyFund.addCategory')}
          onChange={addCategory}
          type="EXPENSE"
          value=""
        />
      ) : (
        <p className={styles.hint}>{t('goals.emergencyFund.categoryLimit')}</p>
      )}
      <p className={styles.hint}>{t('goals.emergencyFund.descendantsHint')}</p>
    </div>
  );
}
