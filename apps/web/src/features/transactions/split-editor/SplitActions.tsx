import { useRef, useState } from 'react';
import { Icon } from '@/components/ui/icon/Icon';
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
  const equalButton = useRef<HTMLButtonElement>(null);
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
    <div className={styles.actions}>
      <button
        aria-label={t('transactions.split.addRow')}
        className="icon-button"
        disabled={rows.length >= MAX_SPLIT_ROWS}
        onClick={() => onChange([...rows, emptySplitRow()])}
        title={t(
          rows.length >= MAX_SPLIT_ROWS
            ? 'transactions.split.rowLimit'
            : 'transactions.split.addRow',
        )}
        type="button"
      >
        <Icon name="add" />
      </button>
      <button
        aria-label={t('transactions.split.splitEvenly')}
        className="secondary-action"
        disabled={!available}
        onClick={redistribute}
        ref={equalButton}
        title={t(
          available ? 'transactions.split.splitEvenly' : `transactions.split.unavailable.${reason}`,
        )}
        type="button"
      >
        <Icon name="columns" size={18} />
        {t('transactions.split.equalLabel')}
      </button>
      {canUndo ? (
        <button
          aria-label={t('transactions.split.undo')}
          className="icon-ghost"
          onClick={() => {
            onChange(undo.before);
            setUndo(null);
            equalButton.current?.focus();
          }}
          title={t('transactions.split.undo')}
          type="button"
        >
          <Icon name="arrow-left" />
        </button>
      ) : null}
    </div>
  );
}
