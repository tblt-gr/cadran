import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { formatAmount } from '@/lib/decimal';
import styles from './GroupMembers.module.css';

interface GroupMembersProps {
  accounts: Account[];
}

/**
 * The exclusive members of one group: accounts whose primary group is this
 * one. Amounts are the backend display strings; the list never adds them.
 */
export function GroupMembers({ accounts }: GroupMembersProps) {
  const { i18n, t } = useTranslation();

  if (accounts.length === 0) {
    return <p className={styles.empty}>{t('accountGroups.list.noAccounts')}</p>;
  }

  return (
    <ul className={styles.list} aria-label={t('accountGroups.list.accounts')}>
      {accounts.map((account) => (
        <li key={account.id}>
          <span>{account.label}</span>
          <MemberAmount
            account={account}
            language={i18n.language}
            missing={t('accounts.balances.missing')}
          />
        </li>
      ))}
    </ul>
  );
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
      value={formatAmount(valuation.display.value, valuation.display.assetCode, language)}
    />
  );
}
