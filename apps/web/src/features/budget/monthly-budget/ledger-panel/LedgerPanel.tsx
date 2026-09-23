import type { MonthlyLedgerAccountRow, MonthlyLedgerCategoryRow } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { BUDGET_AXES, type BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import { LedgerRow, type LedgerKind } from '@/features/budget/monthly-budget/ledger-row/LedgerRow';
import styles from './LedgerPanel.module.css';

interface LedgerPanelProps {
  actionsAllowed: boolean;
  activeAccountIds?: ReadonlySet<string> | null;
  axis: BudgetAxis | null;
  kind: LedgerKind;
  language: string;
  month: string;
  onAdd: (row: MonthlyLedgerCategoryRow | MonthlyLedgerAccountRow) => void;
  onAxisChange?: (axis: BudgetAxis | null) => void;
  rows: Array<MonthlyLedgerCategoryRow | MonthlyLedgerAccountRow>;
}

export function LedgerPanel({
  actionsAllowed,
  activeAccountIds = null,
  axis,
  kind,
  language,
  month,
  onAdd,
  onAxisChange,
  rows,
}: LedgerPanelProps) {
  const { t } = useTranslation();
  const titleId = `monthly-ledger-${kind}`;

  return (
    <section aria-labelledby={titleId} className={styles.panel}>
      <div className={styles.heading}>
        <div>
          <h3 id={titleId}>{t(`budget.monthly.panels.${kind}`)}</h3>
          <p>{t(`budget.monthly.panelDescriptions.${kind}`)}</p>
        </div>
        {kind === 'expense' && onAxisChange ? (
          <label>
            <span>{t('budget.monthly.axis')}</span>
            <select
              aria-label={t('budget.monthly.axis')}
              onChange={(event) => onAxisChange((event.target.value || null) as BudgetAxis | null)}
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
              row={row}
            />
          ))}
        </ul>
      )}
    </section>
  );
}
