import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { ShareCell } from '@/features/account-groups/share-cell/ShareCell';
import { AccountValuationCell } from '@/features/accounts/account-valuation-cell/AccountValuationCell';
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
                  <AccountValuationCell valuation={account.valuation} />
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
                  <ShareCell share={account.share} />
                </td>
                <td>
                  <StatusBadge tone={STATUS_TONE[account.status]}>
                    {account.status === 'CLOSED' && account.closedOn
                      ? `${t('accounts.statuses.CLOSED')} · ${account.closedOn}`
                      : t(`accounts.statuses.${account.status}`)}
                  </StatusBadge>
                </td>
                <td className={styles.rowActions}>
                  {/* Reading the rules changes nothing, so an archived or closed
                      account still answers for the dates it was open. */}
                  <button
                    aria-label={t('accounts.list.recordBalanceOfAccount', { label: account.label })}
                    className="secondary-action"
                    disabled={!account.editable}
                    onClick={() => onRecordBalance(account)}
                    type="button"
                  >
                    {t('accounts.list.recordBalance')}
                  </button>
                  <button
                    aria-label={t('accounts.list.rulesOfAccount', { label: account.label })}
                    className="secondary-action"
                    onClick={() => onRules(account)}
                    type="button"
                  >
                    {t('accounts.list.rules')}
                  </button>
                  <button
                    aria-label={t('accounts.list.editAccount', { label: account.label })}
                    className="secondary-action"
                    disabled={!account.editable}
                    onClick={() => onEdit(account)}
                    type="button"
                  >
                    {t('accounts.list.edit')}
                  </button>
                  <button
                    aria-label={t('accounts.list.archiveAccount', { label: account.label })}
                    className="secondary-action"
                    disabled={!account.editable}
                    onClick={() => onArchive(account)}
                    type="button"
                  >
                    {t('accounts.list.archive')}
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
