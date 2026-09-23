import type { MonthlyLedgerAccountRow, MonthlyLedgerCategoryRow } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { CategoryIdentity } from '@/features/categories/category-identity/CategoryIdentity';
import { formatAmount } from '@/lib/decimal';
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
  const amount = renderAmount(row, language, t);
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

  return (
    <li className={row.hasMovements ? styles.row : `${styles.row} ${styles.rowEmpty}`}>
      <div className={styles.summary}>
        {row.hasMovements ? (
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
            <span className={expanded ? styles.chevronOpen : styles.chevron}>
              <Icon name="chevron-right" size={18} />
            </span>
          </button>
        ) : (
          <span aria-hidden="true" className={styles.togglePlaceholder} />
        )}

        <div className={styles.identity}>
          {category ? (
            <CategoryIdentity color={category.color} icon={category.icon} label={category.label} />
          ) : (
            <span className={styles.accountIdentity}>
              <Icon name="accounts" size={18} />
              {row.label}
            </span>
          )}
          <span className={styles.meta}>
            {t('budget.monthly.movementCount', { count: row.movementCount })}
            {category && !category.budgetIncluded ? (
              <span className={styles.marker}>{t('budget.monthly.outOfBudget')}</span>
            ) : null}
            {category?.archived ? (
              <span className={styles.marker}>{t('budget.monthly.archived')}</span>
            ) : null}
          </span>
        </div>

        <span className={styles.amount}>{amount}</span>
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

      {expanded && row.hasMovements ? (
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

function renderAmount(row: LedgerRowData, language: string, t: TFunction) {
  if (!row.hasMovements) return t('budget.monthly.noMovement');
  if (row.total.value !== null && row.total.assetCode !== null) {
    return <MoneyValue value={formatAmount(row.total.value, row.total.assetCode, language)} />;
  }
  return t('budget.monthly.mixedAssets');
}
