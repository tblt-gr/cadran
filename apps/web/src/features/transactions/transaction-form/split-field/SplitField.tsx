import type { CategoryType } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { emptySplitRow } from '@/features/transactions/split-editor/splitAllocation';
import { SplitEditor } from '@/features/transactions/split-editor/SplitEditor';
import type { SplitRowValues } from '@/features/transactions/split-editor/SplitRow';
import styles from './SplitField.module.css';

interface SplitFieldProps {
  assetCode: string;
  onChangeSplitMode: (splitMode: boolean) => void;
  onChangeSplits: (splits: SplitRowValues[]) => void;
  /** Category type the transaction sign expects, offered first in each row. */
  preferredType: CategoryType;
  showErrors: boolean;
  splitMode: boolean;
  splits: SplitRowValues[];
  total: string;
}

/**
 * The switch to an explicit split across several categories and the editor it
 * reveals. While it is on, the single category field of the form steps aside.
 */
export function SplitField({
  assetCode,
  onChangeSplitMode,
  onChangeSplits,
  preferredType,
  showErrors,
  splitMode,
  splits,
  total,
}: SplitFieldProps) {
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
          onChange={onChangeSplits}
          preferredType={preferredType}
          rows={splits}
          showErrors={showErrors}
          total={total}
        />
      ) : null}
    </div>
  );
}
