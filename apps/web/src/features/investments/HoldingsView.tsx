import type { Holdings } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { HoldingsAllocationCard } from './holdings-allocation/HoldingsAllocationCard';
import { HoldingsSummary } from './holdings-summary/HoldingsSummary';
import { HoldingsTable } from './holdings-table/HoldingsTable';
import { HoldingsWarnings } from './holdings-warnings/HoldingsWarnings';
import { UnsplitAccountsNotice } from './unsplit-accounts-notice/UnsplitAccountsNotice';
import { isNoInvestmentAccount } from './holdingsFormat';
import { HoldingsByAccountTable } from './holdings-by-account/HoldingsByAccountTable';
import { SegmentedControl } from '@/components/ui/segmented-control/SegmentedControl';
import type { HoldingsSort, HoldingsViewMode } from './useHoldings';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './InvestmentsPage.module.css';

interface HoldingsViewProps {
  /** Null while the read is pending: the same layout with its values as blocks. */
  holdings: Holdings | null;
  sort: HoldingsSort;
  onSortChange: (sort: HoldingsSort) => void;
  view: HoldingsViewMode;
  onViewChange: (view: HoldingsViewMode) => void;
}

/** Summary, allocation and table of the positions, or the reason there is nothing to list. */
export function HoldingsView({
  holdings,
  sort,
  onSortChange,
  view,
  onViewChange,
}: HoldingsViewProps) {
  const { t } = useTranslation();

  const byAccount = view === 'account';
  const viewControl = (
    <div className={styles.viewSwitch}>
      <SegmentedControl
        disabled={!holdings}
        label={t('investments.view.label')}
        onChange={onViewChange}
        options={[
          { value: 'account', label: t('investments.view.account') },
          { value: 'asset', label: t('investments.view.asset') },
        ]}
        value={view}
      />
    </div>
  );
  if (holdings && holdings.items.length === 0 && (!byAccount || holdings.accounts.length === 0)) {
    const noAccount = isNoInvestmentAccount(holdings);
    // Accounts valued as a total still have totals and an allocation by account.
    const hasTotals = holdings.totals.securitiesValue !== null;
    return (
      <>
        {holdings.accounts.length > 0 ? viewControl : null}
        <section className={`card ${styles.empty}`}>
          <h3>{t(noAccount ? 'investments.empty.noAccount' : 'investments.empty.noPosition')}</h3>
          {noAccount ? (
            <a
              className="primary-action"
              href="/accounts"
              onClick={(event) => handleClientNavigation(event, '/accounts')}
            >
              {t('investments.empty.addAccount')}
            </a>
          ) : null}
        </section>
        {hasTotals ? (
          <>
            <HoldingsSummary holdings={holdings} />
            <HoldingsWarnings holdings={holdings} />
          </>
        ) : null}
        <UnsplitAccountsNotice accounts={holdings.unsplitAccounts} />
        {hasTotals ? (
          <div className={styles.grid}>
            <HoldingsAllocationCard holdings={holdings} view={view} />
          </div>
        ) : null}
      </>
    );
  }

  return (
    <>
      {viewControl}
      <HoldingsSummary holdings={holdings} />
      {holdings ? <HoldingsWarnings holdings={holdings} /> : null}
      {holdings && !byAccount ? (
        <UnsplitAccountsNotice accounts={holdings.unsplitAccounts} />
      ) : null}
      <div className={styles.grid}>
        <HoldingsAllocationCard holdings={holdings} view={view} />
      </div>
      {byAccount ? (
        <HoldingsByAccountTable holdings={holdings} />
      ) : (
        <HoldingsTable holdings={holdings} onSortChange={onSortChange} sort={sort} />
      )}
    </>
  );
}
