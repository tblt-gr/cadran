import { type BudgetAxis } from '@/features/budget/monthly-budget/budgetPeriod';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonBlock } from '@/components/ui/skeleton/Skeleton';
import skeletonStyles from './LedgerPanelsPlaceholder.module.css';
import ledgerStyles from '@/features/budget/monthly-budget/ledger-panels/LedgerPanels.module.css';
import panelStyles from './LedgerPanel.module.css';
import rowStyles from '@/features/budget/monthly-budget/ledger-row/LedgerRow.module.css';
import { LedgerPanelHeader } from './LedgerPanelHeader';

type PanelKind = 'income' | 'expense' | 'account';

/** Pending category rows, shared by the cold page and an axis refetch of expenses only. */
export function LedgerRowsPlaceholder() {
  return (
    <ul aria-hidden="true" className={panelStyles.rows}>
      {Array.from({ length: 4 }, (_, index) => (
        <li className={rowStyles.row} key={index}>
          <div className={rowStyles.summary}>
            <div className={rowStyles.idle}>
              <div className={rowStyles.identity}>
                <span className={rowStyles.name}>
                  <span className={rowStyles.labelText}>
                    <SkeletonBlock className={skeletonStyles.line} />
                  </span>
                  <span className={rowStyles.meta}>
                    <SkeletonBlock className={skeletonStyles.meta} />
                  </span>
                </span>
              </div>
              <span className={rowStyles.amount}>
                <MoneyValue skeletonClassName={skeletonStyles.amount} value={null} />
              </span>
            </div>
            <button className={rowStyles.add} disabled tabIndex={-1} type="button">
              <Icon name="add" size={18} />
            </button>
          </div>
        </li>
      ))}
    </ul>
  );
}

/** Mirrors LedgerPanel element for element: title, total and controls are real; amounts and rows are pending. */
function LedgerPanelSkeleton({ kind, axis }: { kind: PanelKind; axis: BudgetAxis | null }) {
  const titleId = `monthly-ledger-skeleton-${kind}`;
  return (
    <section aria-labelledby={titleId} className={panelStyles.panel}>
      <LedgerPanelHeader
        actionsAllowed={false}
        axis={axis}
        disabled
        kind={kind}
        titleId={titleId}
        totalState="loading"
      />
      <LedgerRowsPlaceholder />
    </section>
  );
}

/** Mirrors the detailed ledger's three panels. */
export function LedgerPanelsPlaceholder({ axis }: { axis: BudgetAxis | null }) {
  return (
    <div className={ledgerStyles.ledger}>
      <div className={ledgerStyles.statuses} />
      <div className={ledgerStyles.panels}>
        <LedgerPanelSkeleton axis={axis} kind="income" />
        <LedgerPanelSkeleton axis={axis} kind="expense" />
        <LedgerPanelSkeleton axis={axis} kind="account" />
      </div>
    </div>
  );
}
