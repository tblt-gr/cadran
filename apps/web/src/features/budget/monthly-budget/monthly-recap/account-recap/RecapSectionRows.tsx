import type { NetWorthAmount, NetWorthShare } from '@cadran/api-client';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { MoneyValue } from '@/components/ui/money-value/MoneyValue';
import { Icon } from '@/components/ui/icon/Icon';
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

/**
 * One group subtotal row followed by the accounts it totals. The subtotal row folds its accounts;
 * its chevron carries the group colour, so the folded state never relies on colour alone.
 */
export function RecapSectionRows({
  expanded,
  onToggle,
  section,
}: {
  expanded: boolean;
  onToggle: () => void;
  section: RecapSection;
}) {
  const { t } = useTranslation();
  const membersId = useId();
  const color = groupColor(section.color);
  const toggle = onToggle;

  return (
    <tbody>
      <tr className={`${styles.groupRow} ${styles.foldable}`} onClick={toggle}>
        <th data-label={t('budget.monthly.recap.accounts.account')} scope="rowgroup">
          <button
            aria-controls={
              section.members.length > 0
                ? section.members.map((_, index) => `${membersId}-${index}`).join(' ')
                : undefined
            }
            aria-expanded={expanded}
            className={styles.fold}
            onClick={(event) => {
              event.stopPropagation();
              toggle();
            }}
            type="button"
          >
            <span
              aria-hidden="true"
              className={styles.chevron}
              data-expanded={expanded}
              style={color ? { color } : undefined}
            >
              <Icon name="chevron-down" size={14} />
            </span>
            {section.label}
            {section.parentLabel === null ? null : (
              <>
                {' '}
                <span className={styles.parentLabel}>({section.parentLabel})</span>
              </>
            )}
          </button>
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
      {section.members.map((member, index) => (
        <AccountRow
          hidden={!expanded}
          id={`${membersId}-${index}`}
          key={member.account.accountId}
          member={member}
        />
      ))}
    </tbody>
  );
}
