import type { Holdings } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { HoldingsAllocationCard } from './holdings-allocation/HoldingsAllocationCard';
import { HoldingsSummary } from './holdings-summary/HoldingsSummary';
import { HoldingsTable } from './holdings-table/HoldingsTable';
import { HoldingsWarnings } from './holdings-warnings/HoldingsWarnings';
import { UnsplitAccountsNotice } from './unsplit-accounts-notice/UnsplitAccountsNotice';
import { isNoInvestmentAccount } from './holdingsFormat';
import type { HoldingsSort } from './useHoldings';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './InvestmentsPage.module.css';

interface HoldingsViewProps {
  /** Null while the read is pending: the same layout with its values as blocks. */
  holdings: Holdings | null;
  sort: HoldingsSort;
  onSortChange: (sort: HoldingsSort) => void;
}

/** Summary, allocation and table of the positions, or the reason there is nothing to list. */
export function HoldingsView({ holdings, sort, onSortChange }: HoldingsViewProps) {
  const { t } = useTranslation();

  if (holdings && holdings.items.length === 0) {
    const noAccount = isNoInvestmentAccount(holdings);
    return (
      <>
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
        <UnsplitAccountsNotice accounts={holdings.unsplitAccounts} />
      </>
    );
  }

  return (
    <>
      <HoldingsSummary holdings={holdings} />
      {holdings ? <HoldingsWarnings holdings={holdings} /> : null}
      {holdings ? <UnsplitAccountsNotice accounts={holdings.unsplitAccounts} /> : null}
      <div className={styles.grid}>
        <HoldingsAllocationCard holdings={holdings} />
      </div>
      <HoldingsTable holdings={holdings} onSortChange={onSortChange} sort={sort} />
    </>
  );
}
