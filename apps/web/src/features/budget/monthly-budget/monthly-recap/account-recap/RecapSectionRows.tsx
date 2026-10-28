import type { NetWorthAmount, NetWorthShare } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { groupColor } from '@/features/accounts/group-color';
import { ShareCell } from '@/features/accounts/groups/share-cell/ShareCell';
import { formatAmount } from '@/lib/decimal';
import styles from './AccountRecap.module.css';
import { AccountRow } from './AccountRow';
import type { RecapSection } from './recapSections';

function SectionValueCell({
  amount,
  share,
}: {
  amount: NetWorthAmount | null;
  share: NetWorthShare;
}) {
  const { i18n, t } = useTranslation();

  if (amount === null) {
    return (
      <EmptyValue
        label={t('states.notCalculable.label')}
        reason={share.reason ? t(`accountGroups.share.reasons.${share.reason}`) : null}
      />
    );
  }

  const shown = amount.display ?? { value: amount.value, assetCode: amount.assetCode };
  return <MoneyValue value={formatAmount(shown.value, shown.assetCode, i18n.language)} />;
}

/** One group subtotal row followed by the accounts it totals. */
export function RecapSectionRows({ section }: { section: RecapSection }) {
  const { t } = useTranslation();

  return (
    <tbody>
      <tr className={styles.groupRow} style={{ borderInlineStartColor: groupColor(section.color) }}>
        <th data-label={t('budget.monthly.recap.accounts.account')} scope="rowgroup">
          {section.label}
          {section.parentLabel === null ? null : (
            <>
              {' '}
              <span className={styles.parentLabel}>({section.parentLabel})</span>
            </>
          )}
        </th>
        <td data-label={t('budget.monthly.recap.accounts.previousShort')}>
          <SectionValueCell amount={section.previous} share={section.share} />
        </td>
        <td data-label={t('budget.monthly.recap.accounts.currentShort')}>
          <SectionValueCell amount={section.current} share={section.share} />
        </td>
        <td className={styles.share} data-label={t('budget.monthly.recap.accounts.share')}>
          <ShareCell share={section.share} />
        </td>
      </tr>
      {section.members.map((member) => (
        <AccountRow color={section.color} key={member.account.accountId} member={member} />
      ))}
    </tbody>
  );
}
