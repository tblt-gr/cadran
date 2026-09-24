import type {
  MonthlyLedgerAccountRow,
  MonthlyLedgerCategoryRow,
  MonthlyRecapMetric,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { BUDGET_AXES, type BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import { LedgerRow, type LedgerKind } from '@/features/budget/monthly-budget/ledger-row/LedgerRow';
import { formatAmount } from '@/lib/decimal';
import styles from './LedgerPanel.module.css';

interface LedgerPanelProps {
  actionsAllowed: boolean;
  activeAccountIds?: ReadonlySet<string> | null;
  axis: BudgetAxis | null;
  kind: LedgerKind;
  language: string;
  month: string;
  onAdd: (row: MonthlyLedgerCategoryRow | MonthlyLedgerAccountRow) => void;
  /** Adds with no row: the only way in when a category panel lists nothing. */
  onAddFree?: () => void;
  onAxisChange?: (axis: BudgetAxis | null) => void;
  onEditTransaction?: (transactionId: string) => void;
  rows: Array<MonthlyLedgerCategoryRow | MonthlyLedgerAccountRow>;
  total?: MonthlyRecapMetric;
  totalState: 'loading' | 'ready' | 'unavailable';
}

function HeaderTotal({ total, totalState }: Pick<LedgerPanelProps, 'total' | 'totalState'>) {
  const { i18n, t } = useTranslation();
  if (totalState === 'loading') return <span>{t('budget.monthly.totalLoading')}</span>;
  if (totalState === 'unavailable' || !total || total.value === null || total.assetCode === null) {
    return <span>{t('states.notCalculable.label')}</span>;
  }

  return <MoneyValue value={formatAmount(total.value, total.assetCode, i18n.language)} />;
}

export function LedgerPanel({
  actionsAllowed,
  activeAccountIds = null,
  axis,
  kind,
  language,
  month,
  onAdd,
  onAddFree,
  onAxisChange,
  onEditTransaction,
  rows,
  total,
  totalState,
}: LedgerPanelProps) {
  const { t } = useTranslation();
  const titleId = `monthly-ledger-${kind}`;

  return (
    <section aria-labelledby={titleId} className={styles.panel}>
      <div className={styles.heading}>
        <div>
          <h3 id={titleId}>{t(`budget.monthly.panels.${kind}`)}</h3>
          <p>{t(`budget.monthly.panelDescriptions.${kind}`)}</p>
          <p className={styles.total}>
            <span>{t(`budget.monthly.panelTotals.${kind}`)}</span>
            <HeaderTotal total={total} totalState={totalState} />
          </p>
        </div>
        <div className={styles.headingActions}>
          {kind === 'expense' && onAxisChange ? (
            <label>
              <span>{t('budget.monthly.axis')}</span>
              <select
                aria-label={t('budget.monthly.axis')}
                onChange={(event) =>
                  onAxisChange((event.target.value || null) as BudgetAxis | null)
                }
                value={axis ?? ''}
              >
                <option value="">{t('budget.monthly.allAxes')}</option>
                {BUDGET_AXES.map((candidate) => (
                  <option key={candidate} value={candidate}>
                    {t(`budget.monthly.axes.${candidate}`)}
                  </option>
                ))}
              </select>
            </label>
          ) : null}
          {onAddFree ? (
            <button
              aria-label={t(
                kind === 'income'
                  ? 'budget.monthly.addFreeIncome'
                  : 'budget.monthly.addFreeExpense',
              )}
              className={styles.add}
              disabled={!actionsAllowed}
              onClick={onAddFree}
              title={!actionsAllowed ? t('budget.monthly.closedReason') : undefined}
              type="button"
            >
              <Icon name="add" size={18} />
            </button>
          ) : null}
        </div>
      </div>

      {rows.length === 0 ? (
        <p className={styles.empty}>{t('budget.monthly.empty')}</p>
      ) : (
        <ul className={styles.rows}>
          {rows.map((row) => (
            <LedgerRow
              actionsAllowed={actionsAllowed}
              addUnavailable={
                kind === 'account' && activeAccountIds !== null && !activeAccountIds.has(row.id)
              }
              axis={axis}
              key={row.id}
              kind={kind}
              language={language}
              month={month}
              onAdd={() => onAdd(row)}
              onEditTransaction={onEditTransaction}
              row={row}
            />
          ))}
        </ul>
      )}
    </section>
  );
}
