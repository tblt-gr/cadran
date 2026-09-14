import type { Account, NetWorthShare, Product, ProductModel } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { ShareCell } from '@/features/accounts/groups/share-cell/ShareCell';
import { AllocationBar } from '@/features/dashboard/allocation-panel/AllocationBar';
import { formatAmount } from '@/lib/decimal';
import { ceilingFillPercent, depositCeilingOf } from '@/lib/depositCeiling';
import styles from './GroupMembers.module.css';

interface GroupMembersProps {
  accounts: Account[];
  models: readonly ProductModel[];
  products: readonly Product[];
  shareByAccount: ReadonlyMap<string, NetWorthShare>;
  sharesStatus: 'error' | 'pending' | 'ready';
  status: 'error' | 'pending' | 'ready';
}

/**
 * The exclusive members of one group: accounts whose primary group is this
 * one. Amounts come from the account list. Exclusive shares come from net
 * worth — the account list never computes a weight, because a page is not
 * the eligible set. The track is the observed balance against the product
 * ceiling, when the origin publishes one.
 */
export function GroupMembers({
  accounts,
  models,
  products,
  shareByAccount,
  sharesStatus,
  status,
}: GroupMembersProps) {
  const { i18n, t } = useTranslation();

  if (status === 'pending') {
    return <p className={styles.empty}>{t('accountGroups.list.accountsLoading')}</p>;
  }

  if (status === 'error') {
    return <p className={styles.empty}>{t('accountGroups.list.accountsUnavailable')}</p>;
  }

  if (accounts.length === 0) {
    return <p className={styles.empty}>{t('accountGroups.list.noAccounts')}</p>;
  }

  return (
    <ul className={styles.list} aria-label={t('accountGroups.list.accounts')}>
      {accounts.map((account) => {
        const fill = memberCeilingFill(account, products, models);
        const share = shareByAccount.get(account.id);

        return (
          <li key={account.id}>
            <span className={styles.name}>{account.label}</span>
            <span className={styles.share}>
              {sharesStatus === 'pending' ? (
                t('accountGroups.list.shareLoading')
              ) : share === undefined ? null : (
                <ShareCell share={share} />
              )}
            </span>
            <MemberAmount
              account={account}
              language={i18n.language}
              missing={t('accounts.balances.missing')}
            />
            {fill === null ? null : <AllocationBar percent={String(fill)} />}
          </li>
        );
      })}
    </ul>
  );
}

function memberCeilingFill(
  account: Account,
  products: readonly Product[],
  models: readonly ProductModel[],
): number | null {
  const ceiling = depositCeilingOf(account, products, models);
  if (ceiling === null || account.valuation.display === null) {
    return null;
  }

  return ceilingFillPercent(account.valuation.display.value, ceiling.value);
}

function MemberAmount({
  account,
  language,
  missing,
}: {
  account: Account;
  language: string;
  missing: string;
}) {
  const valuation = account.valuation;
  if (valuation.quality === 'MISSING' || valuation.belowDisplayStep || valuation.display === null) {
    return <span className={styles.unknown}>{missing}</span>;
  }

  return (
    <MoneyValue
      className={styles.amount}
      value={formatAmount(valuation.display.value, valuation.display.assetCode, language)}
    />
  );
}
