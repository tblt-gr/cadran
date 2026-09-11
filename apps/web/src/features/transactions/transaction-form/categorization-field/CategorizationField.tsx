import type { CategoryType } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { emptySplitRow } from '@/features/transactions/split-editor/splitAllocation';
import { SplitEditor } from '@/features/transactions/split-editor/SplitEditor';
import type { SplitRowValues } from '@/features/transactions/split-editor/SplitRow';
import {
  TransactionCategoryField,
  type SavedCategory,
} from '@/features/transactions/transaction-form/transaction-category-field/TransactionCategoryField';
import styles from './CategorizationField.module.css';

interface CategorizationFieldProps {
  assetCode: string;
  /** Message for a single category the transaction sign would refuse. */
  categoryError: string | null;
  categoryId: string;
  onChangeCategory: (categoryId: string, type: CategoryType | null) => void;
  onChangeSplitMode: (splitMode: boolean) => void;
  onChangeSplits: (splits: SplitRowValues[]) => void;
  /** Category type the transaction sign expects, offered first. */
  preferredType: CategoryType;
  savedCategory: SavedCategory | null;
  showErrors: boolean;
  splitMode: boolean;
  splits: SplitRowValues[];
  total: string;
}

/**
 * How a transaction is categorised: one category through the simple picker,
 * or an explicit split across several. The toggle and the editor it reveals
 * are a single named responsibility, kept out of the transaction form.
 */
export function CategorizationField({
  assetCode,
  categoryError,
  categoryId,
  onChangeCategory,
  onChangeSplitMode,
  onChangeSplits,
  preferredType,
  savedCategory,
  showErrors,
  splitMode,
  splits,
  total,
}: CategorizationFieldProps) {
  const { t } = useTranslation();

  return (
    <div className={styles.field}>
      <label className={styles.toggle}>
        <input
          checked={splitMode}
          onChange={(event) => {
            const next = event.target.checked;
            onChangeSplitMode(next);
            if (next && splits.length === 0) {
              onChangeSplits([emptySplitRow()]);
            }
          }}
          type="checkbox"
        />
        <span>{t('transactions.split.toggle')}</span>
      </label>

      {splitMode ? (
        <SplitEditor
          assetCode={assetCode}
          preferredType={preferredType}
          onChange={onChangeSplits}
          rows={splits}
          showErrors={showErrors}
          total={total}
        />
      ) : (
        <TransactionCategoryField
          error={categoryError}
          onChange={onChangeCategory}
          preferredType={preferredType}
          savedCategory={savedCategory}
          value={categoryId}
        />
      )}
    </div>
  );
}
