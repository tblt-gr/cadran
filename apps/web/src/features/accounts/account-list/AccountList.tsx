import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { ActionMenu } from '@/components/ui/action-menu/ActionMenu';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { ShareCell } from '@/features/account-groups/share-cell/ShareCell';
import { AccountValuationCell } from '@/features/accounts/account-valuation-cell/AccountValuationCell';
import { useProductOptions } from '@/features/accounts/account-wizard/useProductOptions';
import { useTemplateOptions } from '@/features/accounts/account-wizard/useTemplateOptions';
import { useNetWorth } from '@/features/dashboard/net-worth/useNetWorth';
import { depositCeilingOf } from '@/lib/depositCeiling';
import styles from './AccountList.module.css';

interface AccountListProps {
  accounts: Account[];
  onArchive: (account: Account) => void;
  onEdit: (account: Account) => void;
  onRecordBalance: (account: Account) => void;
  onRules: (account: Account) => void;
}

const STATUS_TONE = {
  ACTIVE: 'positive',
  CLOSED: 'info',
  ARCHIVED: 'warning',
} as const;

export function AccountList({
  accounts,
  onArchive,
  onEdit,
  onRecordBalance,
  onRules,
}: AccountListProps) {
  const { t } = useTranslation();
  const asOf = accounts.find((account) => account.valuation.requestedOn)?.valuation.requestedOn;
  const products = useProductOptions(asOf ?? '');
  const models = useTemplateOptions();
  const netWorth = useNetWorth();
  const shareByAccount = new Map(
    (netWorth.data?.contributions ?? []).map((contribution) => [
      contribution.accountId,
      contribution.share,
    ]),
  );

  return (
    <div className={`card ${styles.panel}`}>
      <div className={styles.tableScroll}>
        <table>
          <caption className="sr-only">{t('accounts.list.caption')}</caption>
          <thead>
            <tr>
              <th scope="col">{t('accounts.fields.label')}</th>
              <th scope="col">{t('accounts.fields.kind')}</th>
              <th scope="col">{t('accounts.fields.assetCode')}</th>
              <th scope="col">{t('accounts.fields.valuationMode')}</th>
              <th scope="col">{t('accounts.fields.balance')}</th>
              <th scope="col">{t('accounts.fields.contribution')}</th>
              <th scope="col">{t('accounts.fields.share')}</th>
              <th scope="col">{t('accounts.list.status')}</th>
              <th scope="col">
                <span className="sr-only">{t('accounts.list.actions')}</span>
              </th>
            </tr>
          </thead>
          <tbody>
            {accounts.map((account) => (
              <tr key={account.id}>
                <th scope="row">
                  <span>{account.label}</span>
                  {account.institution || account.maskedIdentifier ? (
                    <small>
                      {[
                        account.institution,
                        account.maskedIdentifier && `••${account.maskedIdentifier}`,
                      ]
                        .filter(Boolean)
                        .join(' · ')}
                    </small>
                  ) : null}
                </th>
                <td>{t(`accounts.kinds.${account.kind}`)}</td>
                <td>{account.assetCode}</td>
                <td>{t(`accounts.valuationModes.${account.valuationMode}`)}</td>
                <td>
                  <AccountValuationCell
                    ceiling={
                      asOf === undefined
                        ? null
                        : depositCeilingOf(account, products.items, models.items)
                    }
                    valuation={account.valuation}
                  />
                </td>
                <td>
                  {t(
                    account.includeInNetWorth
                      ? account.netWorthSign === -1
                        ? 'accounts.contribution.liability'
                        : 'accounts.contribution.asset'
                      : 'accounts.contribution.excluded',
                  )}
                </td>
                <td>
                  <ShareCell share={shareByAccount.get(account.id) ?? account.share} />
                </td>
                <td>
                  <StatusBadge tone={STATUS_TONE[account.status]}>
                    {account.status === 'CLOSED' && account.closedOn
                      ? `${t('accounts.statuses.CLOSED')} · ${account.closedOn}`
                      : t(`accounts.statuses.${account.status}`)}
                  </StatusBadge>
                </td>
                <td>
                  <ActionMenu
                    items={[
                      {
                        disabled: !account.editable,
                        icon: 'balance',
                        id: 'recordBalance',
                        label: t('accounts.list.recordBalanceOfAccount', { label: account.label }),
                        onSelect: () => onRecordBalance(account),
                        text: t('accounts.list.recordBalance'),
                      },
                      {
                        // Reading the rules changes nothing, so an archived or closed
                        // account still answers for the dates it was open.
                        icon: 'rules',
                        id: 'rules',
                        label: t('accounts.list.rulesOfAccount', { label: account.label }),
                        onSelect: () => onRules(account),
                        text: t('accounts.list.rules'),
                      },
                      {
                        disabled: !account.editable,
                        icon: 'edit',
                        id: 'edit',
                        label: t('accounts.list.editAccount', { label: account.label }),
                        onSelect: () => onEdit(account),
                        text: t('accounts.list.edit'),
                      },
                      {
                        disabled: !account.editable,
                        icon: 'archive',
                        id: 'archive',
                        label: t('accounts.list.archiveAccount', { label: account.label }),
                        onSelect: () => onArchive(account),
                        text: t('accounts.list.archive'),
                      },
                    ]}
                    label={t('accounts.list.openActions', { label: account.label })}
                  />
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
