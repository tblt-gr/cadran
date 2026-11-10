import type { CategoryType } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { categoryContradictsAmount } from '@/features/transactions/categorySign';
import { isCanonicalDecimal, isZeroDecimal } from '@/lib/decimal';
import { toSignedAmount, toTypedAmount, type AmountDirection } from '@/lib/signedAmount';
import { SplitRow, type SplitRowValues } from './SplitRow';
import { assignRemainderToRow, remainingAmount } from './splitAllocation';
import { SplitSummary } from './SplitSummary';
import { SplitActions } from './SplitActions';
import { useAssetPrecision } from './useAssetPrecision';
import styles from './SplitEditor.module.css';

interface SplitEditorProps {
  assetCode: string;
  /**
   * Direction the row amounts are typed in: an outflow row is typed without its
   * minus. Absent, rows are read as signed, which is what a refund's unsigned
   * rows and an adjustment need.
   */
  direction?: AmountDirection | null;
  /** Overrides sign-derived categorisation for movements such as a positive refund of an expense. */
  expectedCategoryType?: CategoryType;
  /** Category type the transaction sign expects, offered first in each row. */
  preferredType: CategoryType;
  onChange: (rows: SplitRowValues[]) => void;
  rows: SplitRowValues[];
  showErrors: boolean;
  total: string;
}

/**
 * Splits a transaction across categories: rows, add/remove, the live
 * remaining amount and the two allocation helpers. Mounted inside the
 * transaction modal — it never opens its own overlay.
 */
export function SplitEditor({
  assetCode,
  direction = null,
  expectedCategoryType,
  preferredType,
  onChange,
  rows,
  showErrors,
  total,
}: SplitEditorProps) {
  const { t } = useTranslation();
  const precision = useAssetPrecision(assetCode);
  // The transaction amount itself can be blank or mid-entry (a fresh draft,
  // a stray "-" or a locale comma) before it becomes a canonical decimal: the
  // remaining amount and both helpers stay non-calculable rather than reading
  // it as zero or throwing, since neither would be the real figure.
  const exactTotal = isCanonicalDecimal(total) ? total : null;
  const signedRows = rows.map((row) => ({ ...row, amount: toSignedAmount(row.amount, direction) }));
  const amounts = signedRows.map((row) => (isCanonicalDecimal(row.amount) ? row.amount : '0'));
  const remaining = exactTotal === null ? null : remainingAmount(exactTotal, amounts);
  const sumBalanced = remaining !== null && isZeroDecimal(remaining);
  const signMismatch = (row: SplitRowValues): boolean =>
    exactTotal !== null &&
    isCanonicalDecimal(row.amount) &&
    !isZeroDecimal(row.amount) &&
    exactTotal.startsWith('-') !== row.amount.startsWith('-');
  // A row whose amount cannot become a real split — inexact, zero, or the
  // wrong sign — is flagged on the amount field itself.
  const amountInvalid = (row: SplitRowValues): boolean =>
    !isCanonicalDecimal(row.amount) || isZeroDecimal(row.amount) || signMismatch(row);
  // The type of a row's category when the transaction sign refuses it, else null.
  const mismatchedType = (row: SplitRowValues): CategoryType | null =>
    row.categoryType &&
    exactTotal !== null &&
    (expectedCategoryType === undefined
      ? categoryContradictsAmount(row.categoryType, exactTotal)
      : row.categoryType !== expectedCategoryType)
      ? row.categoryType
      : null;
  // A row missing its category blocks submission exactly as much as a bad
  // amount does, even though no single field carries that error visually
  // beyond the shared banner below.
  const rowIsIncomplete = (row: SplitRowValues): boolean =>
    row.categoryId === '' || mismatchedType(row) !== null || amountInvalid(row);
  const categoryIds = rows.map((row) => row.categoryId).filter((id) => id !== '');
  const duplicateCategoryIds = new Set(
    categoryIds.filter((id, index) => categoryIds.indexOf(id) !== index),
  );
  const allocationValid =
    rows.length === 0 ||
    (sumBalanced && duplicateCategoryIds.size === 0 && !signedRows.some(rowIsIncomplete));

  function updateRow(index: number, values: SplitRowValues) {
    onChange(rows.map((row, position) => (position === index ? values : row)));
  }

  function removeRow(index: number) {
    onChange(rows.filter((_, position) => position !== index));
  }

  function assignRemainder(index: number) {
    if (exactTotal === null) {
      return;
    }

    const nextAmounts = assignRemainderToRow(exactTotal, amounts, index);
    onChange(rows.map((row, position) => typedRow(row, nextAmounts[position])));
  }

  function typedRow(row: SplitRowValues, signed: string | undefined): SplitRowValues {
    return { ...row, amount: signed === undefined ? row.amount : toTypedAmount(signed, direction) };
  }

  return (
    <div className={styles.editor}>
      <SplitSummary
        assetCode={assetCode}
        complete={allocationValid && rows.length > 0}
        remaining={remaining}
        total={exactTotal}
      />
      {showErrors && !allocationValid ? (
        <p className={styles.error} role="alert">
          {t('transactions.split.remainingError')}
        </p>
      ) : null}

      <ol className={styles.rows}>
        {rows.map((row, index) => {
          const refusedType = mismatchedType(signedRows[index]!);

          return (
            <li key={row.key}>
              <SplitRow
                assetCode={assetCode}
                direction={direction}
                categoryError={
                  refusedType === null
                    ? null
                    : t(`transactions.validation.categoryMismatch.${refusedType}`)
                }
                duplicateCategory={
                  row.categoryId !== '' && duplicateCategoryIds.has(row.categoryId)
                }
                index={index}
                invalid={showErrors && amountInvalid(signedRows[index]!)}
                missingCategory={showErrors && row.categoryId === ''}
                onChange={(values) => updateRow(index, values)}
                onRemove={() => removeRow(index)}
                preferredType={preferredType}
                removable
                values={row}
              />
              {!sumBalanced ? (
                <button
                  className="secondary-action"
                  disabled={exactTotal === null}
                  title={t('transactions.split.remainderHelp')}
                  onClick={() => assignRemainder(index)}
                  type="button"
                >
                  {t('transactions.split.assignRemainder')}
                </button>
              ) : null}
            </li>
          );
        })}
      </ol>

      <SplitActions
        direction={direction}
        onChange={onChange}
        precision={precision}
        rows={rows}
        total={exactTotal}
      />
    </div>
  );
}
