import { groupColor } from '@/features/accounts/group-color';
import type {
  MonthlyAccountValue,
  MonthlyRecapAccount,
  MonthlyRecapGroup,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Icon } from '@/components/ui/icon/Icon';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { ShareCell } from '@/features/accounts/groups/share-cell/ShareCell';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatAmount } from '@/lib/decimal';
import styles from './AccountRecap.module.css';
import { AccountBalanceHeading } from './AccountBalanceHeading';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';

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
    <span className={styles.amount}>
      <MoneyValue value={formatAmount(value.value, value.assetCode, i18n.language)} />
    </span>
  );
}

function GroupValueCell({
  group,
  period,
}: {
  group: MonthlyRecapGroup;
  period: 'previous' | 'current';
}) {
  const { i18n, t } = useTranslation();
  const amount = period === 'previous' ? group.previousValue : group.value;

  if (amount === null) {
    return (
      <EmptyValue
        label={t('states.notCalculable.label')}
        reason={group.share.reason ? t(`accountGroups.share.reasons.${group.share.reason}`) : null}
      />
    );
  }

  const shown = amount.display ?? { value: amount.value, assetCode: amount.assetCode };
  return <MoneyValue value={formatAmount(shown.value, shown.assetCode, i18n.language)} />;
}

function AccountRow({ account, color }: { account: MonthlyRecapAccount; color?: string | null }) {
  const { t } = useTranslation();
  const href = `/accounts/${account.accountId}`;

  return (
    <tr style={color === undefined ? undefined : { borderInlineStartColor: groupColor(color) }}>
      <th data-label={t('budget.monthly.recap.accounts.account')} scope="row">
        <a href={href} onClick={(event) => handleClientNavigation(event, href)}>
          {account.label}
        </a>
        {account.institution === null ? null : (
          <small className={styles.institution} title={account.institution}>
            {account.institution}
          </small>
        )}
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
      <td className={styles.share} data-label={t('budget.monthly.recap.accounts.share')}>
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
  const { t } = useTranslation();
  const staleAge = oldestStaleAge(accounts);
  const staleLabel =
    staleAge === null ? undefined : t('budget.monthly.recap.accounts.stale', { days: staleAge });
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
      <div className={styles.heading}>
        <h3 id="monthly-recap-accounts-title">{t('budget.monthly.recap.accounts.title')}</h3>
        {staleAge === null ? null : (
          <span aria-label={staleLabel} className={styles.stale} role="img" title={staleLabel}>
            <Icon name="alert" size={14} />
          </span>
        )}
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
            {groups.map((group) => {
              const members = byGroup.get(group.groupId) ?? [];
              if (members.length === 0) return null;

              return (
                <tbody key={group.groupId}>
                  <tr
                    className={styles.groupRow}
                    style={{ borderInlineStartColor: groupColor(group.color) }}
                  >
                    <th data-label={t('budget.monthly.recap.accounts.account')} scope="rowgroup">
                      {group.label}
                    </th>
                    <td data-label={t('budget.monthly.recap.accounts.previousShort')}>
                      <GroupValueCell group={group} period="previous" />
                    </td>
                    <td data-label={t('budget.monthly.recap.accounts.currentShort')}>
                      <GroupValueCell group={group} period="current" />
                    </td>
                    <td
                      className={styles.share}
                      data-label={t('budget.monthly.recap.accounts.share')}
                    >
                      <ShareCell share={group.share} />
                    </td>
                  </tr>
                  {members.map((account) => (
                    <AccountRow account={account} color={group.color} key={account.accountId} />
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
