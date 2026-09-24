import type {
  MonthlyAccountValue,
  MonthlyRecapAccount,
  MonthlyRecapGroup,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { ShareCell } from '@/features/accounts/groups/share-cell/ShareCell';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatAmount, formatCalendarDay } from '@/lib/decimal';
import styles from './AccountRecap.module.css';

interface AccountRecapProps {
  accounts: MonthlyRecapAccount[];
  currentAsOf: string;
  groups: MonthlyRecapGroup[];
  previousAsOf: string;
}

function AccountValueCell({ value }: { value: MonthlyAccountValue | null }) {
  const { i18n, t } = useTranslation();

  if (value === null) {
    return <span className={styles.unavailable}>{t('budget.monthly.recap.accounts.notOpen')}</span>;
  }
  if (value.value === null || value.assetCode === null) {
    return (
      <span className={styles.unavailable}>
        {t('budget.monthly.recap.accounts.missingValuation')}
      </span>
    );
  }

  return (
    <span>
      <MoneyValue value={formatAmount(value.value, value.assetCode, i18n.language)} />
      {value.quality === 'STALE' && value.ageDays !== null ? (
        <small>{t('budget.monthly.recap.accounts.stale', { days: value.ageDays })}</small>
      ) : null}
    </span>
  );
}

function GroupValueCell({ group }: { group: MonthlyRecapGroup }) {
  const { i18n, t } = useTranslation();

  if (group.value === null) {
    return (
      <span className={styles.unavailable}>
        {t('states.notCalculable.label')}
        {group.share.reason ? (
          <small>{t(`accountGroups.share.reasons.${group.share.reason}`)}</small>
        ) : null}
      </span>
    );
  }

  const shown = group.value.display ?? {
    value: group.value.value,
    assetCode: group.value.assetCode,
  };
  return <MoneyValue value={formatAmount(shown.value, shown.assetCode, i18n.language)} />;
}

function AccountRow({ account }: { account: MonthlyRecapAccount }) {
  const { t } = useTranslation();
  const href = `/accounts/${account.accountId}`;

  return (
    <tr>
      <th data-label={t('budget.monthly.recap.accounts.account')} scope="row">
        <a href={href} onClick={(event) => handleClientNavigation(event, href)}>
          {account.label}
        </a>
        {!account.eligible ? (
          <small className={styles.ineligible}>
            {t('budget.monthly.recap.accounts.ineligible')}
          </small>
        ) : null}
      </th>
      <td data-label={t('budget.monthly.recap.accounts.previousShort')}>
        <AccountValueCell value={account.previousValue} />
      </td>
      <td data-label={t('budget.monthly.recap.accounts.currentShort')}>
        <AccountValueCell value={account.currentValue} />
      </td>
      <td data-label={t('budget.monthly.recap.accounts.share')}>
        <ShareCell share={account.share} />
      </td>
    </tr>
  );
}

/**
 * One row per included account, grouped by primary group. Each primary group
 * carries a single subtotal and share row — a secondary label never adds a
 * second one for the same account — rendered once as the group's own table
 * row rather than repeated on every member row. An account with no primary
 * group is listed under its own section with no subtotal, since it belongs
 * to no exclusive bucket.
 */
export function AccountRecap({ accounts, currentAsOf, groups, previousAsOf }: AccountRecapProps) {
  const { i18n, t } = useTranslation();
  const byGroup = new Map<string, MonthlyRecapAccount[]>();
  const ungrouped: MonthlyRecapAccount[] = [];
  for (const account of accounts) {
    if (account.primaryGroupId === null) {
      ungrouped.push(account);
      continue;
    }
    const members = byGroup.get(account.primaryGroupId) ?? [];
    members.push(account);
    byGroup.set(account.primaryGroupId, members);
  }

  return (
    <section aria-labelledby="monthly-recap-accounts-title" className={`card ${styles.panel}`}>
      <h3 id="monthly-recap-accounts-title">{t('budget.monthly.recap.accounts.title')}</h3>
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
                  {t('budget.monthly.recap.accounts.previous', {
                    date: formatCalendarDay(previousAsOf, i18n.language),
                  })}
                </th>
                <th scope="col">
                  {t('budget.monthly.recap.accounts.current', {
                    date: formatCalendarDay(currentAsOf, i18n.language),
                  })}
                </th>
                <th scope="col">{t('budget.monthly.recap.accounts.share')}</th>
              </tr>
            </thead>
            {groups.map((group) => {
              const members = byGroup.get(group.groupId) ?? [];
              if (members.length === 0) return null;

              return (
                <tbody key={group.groupId}>
                  <tr className={styles.groupRow}>
                    <th
                      colSpan={2}
                      data-label={t('budget.monthly.recap.accounts.account')}
                      scope="rowgroup"
                    >
                      {group.label}
                    </th>
                    <td data-label={t('budget.monthly.recap.accounts.currentShort')}>
                      <GroupValueCell group={group} />
                    </td>
                    <td data-label={t('budget.monthly.recap.accounts.share')}>
                      <ShareCell share={group.share} />
                    </td>
                  </tr>
                  {members.map((account) => (
                    <AccountRow account={account} key={account.accountId} />
                  ))}
                </tbody>
              );
            })}
            {ungrouped.length > 0 ? (
              <tbody>
                <tr className={styles.groupRow}>
                  <th colSpan={4} scope="rowgroup">
                    {t('budget.monthly.recap.accounts.ungrouped')}
                  </th>
                </tr>
                {ungrouped.map((account) => (
                  <AccountRow account={account} key={account.accountId} />
                ))}
              </tbody>
            ) : null}
          </table>
        </div>
      )}
    </section>
  );
}
