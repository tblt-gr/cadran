import type { MonthlyRecapMetric } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { BUDGET_AXES, type BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import type { LedgerKind } from '@/features/budget/monthly-budget/ledger-row/LedgerRow';
import { formatAmount } from '@/lib/decimal';
import { toSignedAmount } from '@/lib/signedAmount';
import styles from './LedgerPanel.module.css';

interface LedgerPanelHeaderProps {
  actionsAllowed: boolean;
  axis: BudgetAxis | null;
  disabled?: boolean;
  kind: LedgerKind;
  onAddFree?: () => void;
  onAxisChange?: (axis: BudgetAxis | null) => void;
  onShowEmptyAccountsChange?: (show: boolean) => void;
  showEmptyAccounts?: boolean;
  titleId: string;
  total?: MonthlyRecapMetric;
  totalState: 'loading' | 'ready' | 'unavailable';
}

function HeaderTotal({
  kind,
  total,
  totalState,
}: Pick<LedgerPanelHeaderProps, 'kind' | 'total' | 'totalState'>) {
  const { i18n, t } = useTranslation();
  if (totalState === 'loading') {
    return <MoneyValue skeletonClassName={styles.totalSkeleton} value={null} />;
  }
  if (totalState === 'unavailable' || !total || total.value === null || total.assetCode === null) {
    return <EmptyValue label={t('states.notCalculable.label')} />;
  }

  const amount = toSignedAmount(total.value, kind === 'expense' ? 'OUTFLOW' : null);
  const value = <MoneyValue value={formatAmount(amount, total.assetCode, i18n.language)} />;
  // Transfers stay white unless the net is negative; income and expense keep a fixed tone.
  const negativeTransfer = kind === 'account' && amount.startsWith('-');
  if (kind === 'account' && !negativeTransfer) return value;
  return <span className={kind === 'income' ? styles.income : styles.expense}>{value}</span>;
}

/** Loaded and first-load panels share the same static header and controls. */
export function LedgerPanelHeader({
  actionsAllowed,
  axis,
  disabled = false,
  kind,
  onAddFree,
  onAxisChange,
  onShowEmptyAccountsChange,
  showEmptyAccounts = false,
  titleId,
  total,
  totalState,
}: LedgerPanelHeaderProps) {
  const { t } = useTranslation();
  return (
    <header className={styles.heading}>
      <div className={styles.headingMain}>
        <h3 id={titleId}>{t(`budget.monthly.panels.${kind}`)}</h3>
        <p className={styles.total}>
          <span className="sr-only">{t(`budget.monthly.panelTotals.${kind}`)}</span>
          <HeaderTotal kind={kind} total={total} totalState={totalState} />
        </p>
      </div>
      <div className={styles.headingActions}>
        {kind === 'expense' ? (
          <label className={styles.axis}>
            <span className="sr-only">{t('budget.monthly.axis')}</span>
            <select
              aria-label={t('budget.monthly.axis')}
              disabled={disabled}
              value={axis ?? ''}
              onChange={(event) =>
                onAxisChange?.((event.target.value || null) as BudgetAxis | null)
              }
            >
              <option value="">{t('budget.monthly.allAxes')}</option>
              {BUDGET_AXES.map((candidate) => (
                <option key={candidate} value={candidate}>
                  {t(`budget.monthly.axes.${candidate}`)}
                </option>
              ))}
            </select>
            <span aria-hidden="true" className={styles.axisArrow}>
              <Icon name="arrow-down" size={14} />
            </span>
          </label>
        ) : null}
        {kind === 'account' ? (
          <button
            aria-label={t('budget.monthly.showEmptyAccounts')}
            aria-pressed={showEmptyAccounts}
            className={styles.add}
            disabled={disabled}
            onClick={() => onShowEmptyAccountsChange?.(!showEmptyAccounts)}
            title={t(
              showEmptyAccounts
                ? 'budget.monthly.hideEmptyAccounts'
                : 'budget.monthly.showEmptyAccounts',
            )}
            type="button"
          >
            <Icon name={showEmptyAccounts ? 'eye' : 'eye-off'} size={18} />
          </button>
        ) : (
          <button
            aria-label={t(
              kind === 'income' ? 'budget.monthly.addFreeIncome' : 'budget.monthly.addFreeExpense',
            )}
            className={styles.add}
            disabled={disabled || !actionsAllowed}
            onClick={onAddFree}
            title={!actionsAllowed && !disabled ? t('budget.monthly.closedReason') : undefined}
            type="button"
          >
            <Icon name="add" size={18} />
          </button>
        )}
      </div>
    </header>
  );
}
