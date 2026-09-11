import type { CategoryType } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatAmount, isCanonicalDecimal, isZeroDecimal } from '@/lib/decimal';
import { SplitRow, type SplitRowValues } from './SplitRow';
import {
  MAX_SPLIT_ROWS,
  assignRemainderToRow,
  canEvenSplit,
  emptySplitRow,
  evenSplitRows,
  remainingAmount,
} from './splitAllocation';
import { useAssetPrecision } from './useAssetPrecision';
import styles from './SplitEditor.module.css';

interface SplitEditorProps {
  assetCode: string;
  categoryType: CategoryType;
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
  categoryType,
  onChange,
  rows,
  showErrors,
  total,
}: SplitEditorProps) {
  const { t, i18n } = useTranslation();
  const precision = useAssetPrecision(assetCode);
  // The transaction amount itself can be blank or mid-entry (a fresh draft,
  // a stray "-" or a locale comma) before it becomes a canonical decimal: the
  // remaining amount and both helpers stay non-calculable rather than reading
  // it as zero or throwing, since neither would be the real figure.
  const exactTotal = isCanonicalDecimal(total) ? total : null;
  const amounts = rows.map((row) => (isCanonicalDecimal(row.amount) ? row.amount : '0'));
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
  // A row missing its category blocks submission exactly as much as a bad
  // amount does, even though no single field carries that error visually
  // beyond the shared banner below.
  const rowIsIncomplete = (row: SplitRowValues): boolean =>
    row.categoryId === '' || amountInvalid(row);
  const categoryIds = rows.map((row) => row.categoryId).filter((id) => id !== '');
  const duplicateCategoryIds = new Set(
    categoryIds.filter((id, index) => categoryIds.indexOf(id) !== index),
  );
  const allocationValid =
    rows.length === 0 ||
    (sumBalanced && duplicateCategoryIds.size === 0 && !rows.some(rowIsIncomplete));
  const evenSplitAvailable =
    precision !== null && exactTotal !== null && canEvenSplit(exactTotal, precision, rows.length);

  function updateRow(index: number, values: SplitRowValues) {
    onChange(rows.map((row, position) => (position === index ? values : row)));
  }

  function removeRow(index: number) {
    onChange(rows.filter((_, position) => position !== index));
  }

  function addRow() {
    onChange([...rows, emptySplitRow()]);
  }

  function assignRemainder(index: number) {
    if (exactTotal === null) {
      return;
    }

    const nextAmounts = assignRemainderToRow(exactTotal, amounts, index);
    onChange(
      rows.map((row, position) => ({ ...row, amount: nextAmounts[position] ?? row.amount })),
    );
  }

  function splitEvenly() {
    if (precision === null || exactTotal === null) {
      return;
    }

    const nextAmounts = evenSplitRows(exactTotal, precision, rows.length);
    onChange(
      rows.map((row, position) => ({ ...row, amount: nextAmounts[position] ?? row.amount })),
    );
  }

  return (
    <div className={styles.editor}>
      <div className={styles.remaining}>
        <span id="split-remaining-label">{t('transactions.split.remaining')}</span>
        <strong aria-labelledby="split-remaining-label" aria-live="polite" role="status">
          {remaining === null
            ? t('transactions.split.remainingUnknown')
            : formatAmount(remaining, assetCode, i18n.language)}
        </strong>
      </div>
      {showErrors && !allocationValid ? (
        <p className={styles.error} role="alert">
          {t('transactions.split.remainingError')}
        </p>
      ) : null}

      <ol className={styles.rows}>
        {rows.map((row, index) => (
          <li key={row.key}>
            <SplitRow
              duplicateCategory={row.categoryId !== '' && duplicateCategoryIds.has(row.categoryId)}
              index={index}
              invalid={showErrors && amountInvalid(row)}
              onChange={(values) => updateRow(index, values)}
              onRemove={() => removeRow(index)}
              removable
              type={categoryType}
              values={row}
            />
            {!sumBalanced ? (
              <button
                className="secondary-action"
                onClick={() => assignRemainder(index)}
                type="button"
              >
                {t('transactions.split.assignRemainder')}
              </button>
            ) : null}
          </li>
        ))}
      </ol>

      <div className={styles.actions}>
        <button
          className="secondary-action"
          disabled={rows.length >= MAX_SPLIT_ROWS}
          onClick={addRow}
          type="button"
        >
          {t('transactions.split.addRow')}
        </button>
        <button
          className="secondary-action"
          disabled={!evenSplitAvailable}
          onClick={splitEvenly}
          type="button"
        >
          {t('transactions.split.splitEvenly')}
        </button>
      </div>
      {!evenSplitAvailable ? (
        <p className={styles.hint}>{t('transactions.split.splitEvenlyUnavailable')}</p>
      ) : null}
    </div>
  );
}
