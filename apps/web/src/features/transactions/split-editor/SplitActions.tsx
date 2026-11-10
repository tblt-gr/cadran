import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { isZeroDecimal } from '@/lib/decimal';
import { toTypedAmount, type AmountDirection } from '@/lib/signedAmount';
import type { SplitRowValues } from './SplitRow';
import { canEvenSplit, emptySplitRow, evenSplitRows, MAX_SPLIT_ROWS } from './splitAllocation';
import styles from './SplitEditor.module.css';

interface SplitActionsProps {
  direction: AmountDirection | null;
  onChange: (rows: SplitRowValues[]) => void;
  precision: number | null;
  rows: SplitRowValues[];
  total: string | null;
}

/** Allocation helpers own a one-step undo, valid only while their result is untouched. */
export function SplitActions({ direction, onChange, precision, rows, total }: SplitActionsProps) {
  const { t } = useTranslation();
  const id = useId();
  const [undo, setUndo] = useState<{
    before: SplitRowValues[];
    after: string;
    context: string;
  } | null>(null);
  const context = JSON.stringify([total, direction, precision]);
  const canUndo = undo !== null && undo.after === JSON.stringify(rows) && undo.context === context;
  const available =
    total !== null && precision !== null && canEvenSplit(total, precision, rows.length);
  const reason =
    total === null || isZeroDecimal(total)
      ? 'invalidTotal'
      : rows.length < 2
        ? 'fewRows'
        : precision === null
          ? 'unknownPrecision'
          : rows.length > MAX_SPLIT_ROWS
            ? 'tooManyRows'
            : (total.split('.')[1]?.length ?? 0) > precision
              ? 'tooPrecise'
              : 'tooSmall';
  function redistribute() {
    if (!available || total === null || precision === null) return;
    const amounts = evenSplitRows(total, precision, rows.length);
    const next = rows.map((row, index) => ({
      ...row,
      amount: toTypedAmount(amounts[index]!, direction),
    }));
    setUndo({ before: rows, after: JSON.stringify(next), context });
    onChange(next);
  }
  return (
    <div>
      <div className={styles.actions}>
        <button
          aria-describedby={`${id}-add`}
          className="secondary-action"
          disabled={rows.length >= MAX_SPLIT_ROWS}
          onClick={() => onChange([...rows, emptySplitRow()])}
          type="button"
        >
          {t('transactions.split.addRow')}
        </button>
        <button
          aria-describedby={`${id}-equal`}
          className="secondary-action"
          disabled={!available}
          onClick={redistribute}
          type="button"
        >
          {t('transactions.split.splitEvenly')}
        </button>
        <button
          className="secondary-action"
          disabled={!canUndo}
          onClick={() => {
            if (canUndo) {
              onChange(undo.before);
              setUndo(null);
            }
          }}
          type="button"
        >
          {t('transactions.split.undo')}
        </button>
      </div>
      <p className={styles.hint} id={`${id}-add`}>
        {t(
          rows.length >= MAX_SPLIT_ROWS
            ? 'transactions.split.rowLimit'
            : 'transactions.split.addHelp',
        )}
      </p>
      <p className={styles.hint} id={`${id}-equal`}>
        {t(available ? 'transactions.split.equalHelp' : `transactions.split.unavailable.${reason}`)}
      </p>
    </div>
  );
}
