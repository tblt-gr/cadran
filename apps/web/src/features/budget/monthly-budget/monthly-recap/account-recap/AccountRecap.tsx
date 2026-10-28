import type { MonthlyRecapAccount, MonthlyRecapGroup } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { GroupingModeToggle } from '@/features/accounts/grouping-mode/GroupingModeToggle';
import { useGroupingMode } from '@/features/accounts/grouping-mode/useGroupingMode';
import styles from './AccountRecap.module.css';
import { AccountBalanceHeading } from './AccountBalanceHeading';
import { AccountRow } from './AccountRow';
import { RecapSectionRows } from './RecapSectionRows';
import { recapSections } from './recapSections';

interface AccountRecapProps {
  accounts: MonthlyRecapAccount[];
  currentAsOf: string;
  groups: MonthlyRecapGroup[];
  previousAsOf: string;
}

/** Oldest valuation age among the stale balances, shown once beside the title. */
function oldestStaleAge(accounts: MonthlyRecapAccount[]): number | null {
  const ages = accounts
    .flatMap((account) => [account.previousValue, account.currentValue])
    .flatMap((value) =>
      value?.quality === 'STALE' && value.ageDays !== null ? [value.ageDays] : [],
    );
  return ages.length === 0 ? null : Math.max(...ages);
}

/**
 * One row per included account, grouped by sub-group (direct totals) or by root group (roll-ups).
 * Each section carries a single subtotal row equal to the accounts it heads. An account with no
 * primary group is listed under its own section with no subtotal.
 */
export function AccountRecap({ accounts, currentAsOf, groups, previousAsOf }: AccountRecapProps) {
  const { t } = useTranslation();
  const staleAge = oldestStaleAge(accounts);
  const staleLabel =
    staleAge === null ? undefined : t('budget.monthly.recap.accounts.stale', { days: staleAge });
  const [mode, setMode] = useGroupingMode('cadran.budget.recap.grouping', 'PRIMARY');
  const { sections, ungrouped } = recapSections(groups, accounts, mode);

  return (
    <section aria-labelledby="monthly-recap-accounts-title" className={`card ${styles.panel}`}>
      <div className={styles.heading}>
        <div className={styles.titleGroup}>
          <h3 id="monthly-recap-accounts-title">{t('budget.monthly.recap.accounts.title')}</h3>
          {staleAge === null ? null : (
            <span aria-label={staleLabel} className={styles.stale} role="img" title={staleLabel}>
              <Icon name="alert" size={14} />
            </span>
          )}
        </div>
        <GroupingModeToggle mode={mode} onChange={setMode} />
      </div>
      {accounts.length === 0 ? (
        <p className={styles.empty}>{t('budget.monthly.recap.accounts.empty')}</p>
      ) : (
        <div className={styles.tableScroll}>
          <table>
            <caption className="sr-only">{t('budget.monthly.recap.accounts.caption')}</caption>
            <thead>
              <tr>
                <th scope="col">{t('budget.monthly.recap.accounts.account')}</th>
                <th scope="col">
                  <AccountBalanceHeading date={previousAsOf} />
                </th>
                <th scope="col">
                  <AccountBalanceHeading date={currentAsOf} />
                </th>
                <th className={styles.share} scope="col">
                  {t('budget.monthly.recap.accounts.share')}
                </th>
              </tr>
            </thead>
            {sections.map((section) => (
              <RecapSectionRows key={section.groupId} section={section} />
            ))}
            {ungrouped.length > 0 ? (
              <tbody>
                <tr className={styles.groupRow}>
                  <th colSpan={4} scope="rowgroup">
                    {t('budget.monthly.recap.accounts.ungrouped')}
                  </th>
                </tr>
                {ungrouped.map((account) => (
                  <AccountRow key={account.accountId} member={{ account, subGroup: null }} />
                ))}
              </tbody>
            ) : null}
          </table>
        </div>
      )}
    </section>
  );
}
