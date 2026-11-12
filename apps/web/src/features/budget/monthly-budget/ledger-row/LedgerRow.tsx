import type { MonthlyLedgerAccountRow, MonthlyLedgerCategoryRow } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { ColorDot } from '@/components/ui/color-dot/ColorDot';
import { canonicalGroupColor } from '@/features/accounts/group-color';
import { canonicalCategoryColor } from '@/features/categories/category-color';
import { formatAmount, negateDecimal } from '@/lib/decimal';
import type { BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import { MovementDetails } from '@/features/budget/monthly-budget/movement-details/MovementDetails';
import styles from './LedgerRow.module.css';

export type LedgerKind = 'income' | 'expense' | 'account';
export type LedgerRowData = MonthlyLedgerCategoryRow | MonthlyLedgerAccountRow;

interface LedgerRowProps {
  actionsAllowed: boolean;
  addUnavailable?: boolean;
  axis: BudgetAxis | null;
  kind: LedgerKind;
  language: string;
  month: string;
  onAdd: () => void;
  onEditTransaction?: (transactionId: string) => void;
  row: LedgerRowData;
}

export function LedgerRow({
  actionsAllowed,
  addUnavailable = false,
  axis,
  kind,
  language,
  month,
  onAdd,
  onEditTransaction,
  row,
}: LedgerRowProps) {
  const { t } = useTranslation();
  const [expanded, setExpanded] = useState(false);
  const detailsId = useId();
  const category = kind === 'account' ? null : (row as MonthlyLedgerCategoryRow);
  // Pending movements are listed for information: they open the detail but feed no sum.
  const explainable = row.hasMovements || row.pendingCount > 0;
  const pendingOnly = !row.hasMovements && row.pendingCount > 0;
  const amount = renderAmount(row, pendingOnly, kind, language, t);
  // An account has no color of its own: its dot shows the group it is filed under.
  const color =
    kind === 'account'
      ? canonicalGroupColor((row as MonthlyLedgerAccountRow).primaryGroupColor)
      : canonicalCategoryColor(category?.color);
  const addDisabled = !actionsAllowed || addUnavailable;
  const disabledReason = !actionsAllowed
    ? t('budget.monthly.closedReason')
    : t('budget.monthly.accountUnavailable');
  const actionLabel = t(
    kind === 'income'
      ? 'budget.monthly.addIncome'
      : kind === 'expense'
        ? 'budget.monthly.addExpense'
        : 'budget.monthly.addTransfer',
    { label: row.label },
  );

  const summaryBody = (
    <>
      <span className={styles.identity}>
        <span className={styles.name}>
          <ColorDot color={color} />
          <span className={styles.labelText} title={row.label}>
            {row.label}
          </span>
          {row.movementCount > 1 ? (
            <>
              <span aria-hidden="true" className={styles.meta}>
                ({row.movementCount})
              </span>
              <span className="sr-only">
                {t('budget.monthly.movementCount', { count: row.movementCount })}
              </span>
            </>
          ) : null}
        </span>
        {category && !category.budgetIncluded ? (
          <span className={styles.marker}>{t('budget.monthly.outOfBudget')}</span>
        ) : null}
        {category?.archived ? (
          <span className={styles.marker}>{t('budget.monthly.archived')}</span>
        ) : null}
      </span>
      <span className={styles.amount}>{amount}</span>
    </>
  );

  return (
    <li
      className={
        pendingOnly
          ? `${styles.row} ${styles.rowPending}`
          : explainable
            ? styles.row
            : `${styles.row} ${styles.rowEmpty}`
      }
      data-pending={row.pendingCount > 0 || undefined}
    >
      <div className={styles.summary}>
        {explainable ? (
          <button
            aria-controls={detailsId}
            aria-expanded={expanded}
            aria-label={t(
              expanded ? 'budget.monthly.hideMovements' : 'budget.monthly.showMovements',
              { label: row.label },
            )}
            className={styles.toggle}
            onClick={() => setExpanded((current) => !current)}
            type="button"
          >
            {summaryBody}
          </button>
        ) : (
          <div className={styles.idle}>{summaryBody}</div>
        )}
        <button
          aria-describedby={addDisabled ? `${detailsId}-closed` : undefined}
          aria-label={actionLabel}
          className={styles.add}
          disabled={addDisabled}
          onClick={onAdd}
          title={addDisabled ? disabledReason : undefined}
          type="button"
        >
          <Icon name="add" size={18} />
        </button>
      </div>

      {addDisabled ? (
        <span className="sr-only" id={`${detailsId}-closed`}>
          {disabledReason}
        </span>
      ) : null}

      {expanded && explainable ? (
        <div className={styles.details} id={detailsId}>
          <MovementDetails
            axis={axis}
            editable={actionsAllowed}
            kind={kind}
            language={language}
            month={month}
            onEditTransaction={onEditTransaction}
            rowId={row.id}
          />
        </div>
      ) : null}
    </li>
  );
}

/** A pending-only row shows its pending amount as any amount; a mixed row shows the realised one. */
function renderAmount(
  row: LedgerRowData,
  pendingOnly: boolean,
  kind: LedgerKind,
  language: string,
  t: TFunction,
) {
  if (pendingOnly) {
    return row.pendingAmount === null ? (
      <EmptyValue label={t('budget.monthly.pendingNotCalculable')} />
    ) : (
      <MoneyValue
        value={formatAmount(
          kind === 'expense' ? negateDecimal(row.pendingAmount.value) : row.pendingAmount.value,
          row.pendingAmount.assetCode,
          language,
        )}
      />
    );
  }
  if (!row.hasMovements) return <EmptyValue label={t('budget.monthly.noMovement')} />;
  if (row.total.value !== null && row.total.assetCode !== null) {
    const value = kind === 'expense' ? negateDecimal(row.total.value) : row.total.value;
    return <MoneyValue value={formatAmount(value, row.total.assetCode, language)} />;
  }
  return t('budget.monthly.mixedAssets');
}
