import type { MonthlyAccountValue } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { groupColor } from '@/features/accounts/group-color';
import { ShareCell } from '@/features/accounts/groups/share-cell/ShareCell';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { formatAmount } from '@/lib/decimal';
import styles from './AccountRecap.module.css';
import type { RecapMember } from './recapSections';

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

export function AccountRow({ member, color }: { member: RecapMember; color?: string | null }) {
  const { t } = useTranslation();
  const { account, subGroup } = member;
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
        {subGroup === null ? null : (
          <small className={styles.subGroup}>
            {subGroup.parentLabel === null
              ? subGroup.label
              : `${subGroup.label} (${subGroup.parentLabel})`}
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
