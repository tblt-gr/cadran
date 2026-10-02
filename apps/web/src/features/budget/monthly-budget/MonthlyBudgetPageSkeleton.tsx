import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { SkeletonBlock, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import styles from './MonthlyBudgetPage.module.css';
import skeletonStyles from './MonthlyBudgetPageSkeleton.module.css';
import ledgerStyles from './ledger-panels/LedgerPanels.module.css';
import panelStyles from './ledger-panel/LedgerPanel.module.css';
import rowStyles from './ledger-row/LedgerRow.module.css';
import { MonthlyRecapPlaceholder } from './monthly-recap/MonthlyRecapSkeleton';

type PanelKind = 'income' | 'expense' | 'account';

/** Panel title, description, total label and add button are static and real; amounts and rows are pending. */
function LedgerPanelSkeleton({ kind }: { kind: PanelKind }) {
  const { t } = useTranslation();
  const titleId = `monthly-ledger-skeleton-${kind}`;
  const addLabel =
    kind === 'income' ? 'budget.monthly.addFreeIncome' : 'budget.monthly.addFreeExpense';
  return (
    <section aria-labelledby={titleId} className={panelStyles.panel}>
      <div className={panelStyles.heading}>
        <div>
          <h3 id={titleId}>{t(`budget.monthly.panels.${kind}`)}</h3>
          <p>{t(`budget.monthly.panelDescriptions.${kind}`)}</p>
          <p className={panelStyles.total}>
            <span>{t(`budget.monthly.panelTotals.${kind}`)}</span>
            <SkeletonBlock className={skeletonStyles.amount} />
          </p>
        </div>
        {kind === 'account' ? null : (
          <div className={panelStyles.headingActions}>
            {kind === 'expense' ? (
              <label>
                <span>{t('budget.monthly.axis')}</span>
                <select aria-label={t('budget.monthly.axis')} disabled>
                  <option>{t('budget.monthly.allAxes')}</option>
                </select>
              </label>
            ) : null}
            <button aria-label={t(addLabel)} className={panelStyles.add} disabled type="button">
              <Icon name="add" size={18} />
            </button>
          </div>
        )}
      </div>
      <ul aria-hidden="true" className={panelStyles.rows}>
        {Array.from({ length: 4 }, (_, index) => (
          <li className={rowStyles.row} key={index}>
            <div className={rowStyles.summary}>
              <SkeletonBlock className={skeletonStyles.icon} />
              <div>
                <SkeletonBlock className={skeletonStyles.line} />
                <SkeletonBlock className={skeletonStyles.meta} />
              </div>
              <SkeletonBlock className={skeletonStyles.amount} />
              <SkeletonBlock className={skeletonStyles.icon} />
            </div>
          </li>
        ))}
      </ul>
    </section>
  );
}

export function MonthlyBudgetPageSkeleton({ status }: { status: string }) {
  return (
    <section aria-busy="true" className={styles.workbook} data-testid="monthly-budget-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <div className={ledgerStyles.ledger}>
        <div className={ledgerStyles.panels}>
          <LedgerPanelSkeleton kind="income" />
          <LedgerPanelSkeleton kind="expense" />
          <LedgerPanelSkeleton kind="account" />
        </div>
      </div>
      <MonthlyRecapPlaceholder />
    </section>
  );
}
