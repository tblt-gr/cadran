import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { useAccountProduct } from '@/features/accounts/account-editor/useAccountProduct';
import { useAccountProductModel } from '@/features/accounts/account-editor/useAccountProductModel';
import { AccountValuationCell } from '@/features/accounts/account-valuation-cell/AccountValuationCell';
import { formatCalendarDay } from '@/lib/decimal';
import { useAccountGroupLabel } from './useAccountGroupLabel';
import styles from './AccountIdentityCard.module.css';

interface AccountIdentityCardProps {
  account: Account;
}

const STATUS_TONE = {
  ACTIVE: 'positive',
  CLOSED: 'info',
  ARCHIVED: 'warning',
} as const;

/**
 * Everything that identifies the account and where it stands in its
 * lifecycle: what it is held in, what it was created from, which group it
 * belongs to, when it opened and closed, and its latest dated value.
 *
 * The origin and the group are each read from their own reference rather
 * than duplicated onto the account: a withdrawn product or an archived group
 * still resolves to the name it once had, and a read failure is stated
 * rather than silently blanked.
 */
export function AccountIdentityCard({ account }: AccountIdentityCardProps) {
  const { i18n, t } = useTranslation();
  const product = useAccountProduct(account.productCode, account.openedOn);
  const template = useAccountProductModel(account.productModelId);
  const group = useAccountGroupLabel(account.primaryGroupId);

  const originLabel = account.productCode
    ? product.isPending
      ? t('accounts.detail.identity.productLoading')
      : product.product
        ? product.product.displayName
        : t('accounts.detail.identity.productUnavailable')
    : account.productModelId
      ? template.isPending
        ? t('accounts.detail.identity.productLoading')
        : template.template
          ? template.template.name
          : t('accounts.detail.identity.productUnavailable')
      : t('accounts.detail.identity.manual');

  // An absent primary group and one this hook could not resolve must read
  // differently: the first is a fact about the account, the second is a read
  // failure (an errored request, or a group beyond this hook's page size)
  // that must never be shown as "no group".
  const groupLabel =
    account.primaryGroupId === null
      ? t('accounts.detail.identity.noGroup')
      : group.isPending
        ? t('accounts.detail.identity.groupLoading')
        : group.isError || group.label === null
          ? t('accounts.detail.identity.groupUnavailable')
          : group.label;

  return (
    <div className={`card ${styles.card}`}>
      <div className={styles.heading}>
        <div>
          <p>{t('accounts.detail.identity.title')}</p>
          <h3>{account.label}</h3>
          <span>
            {[account.institution, account.maskedIdentifier && `••${account.maskedIdentifier}`]
              .filter(Boolean)
              .join(' · ') || t('accounts.detail.identity.noInstitution')}
          </span>
        </div>
        <StatusBadge tone={STATUS_TONE[account.status]}>
          {t(`accounts.statuses.${account.status}`)}
        </StatusBadge>
      </div>

      <dl className={styles.list}>
        <div>
          <dt>{t('accounts.fields.kind')}</dt>
          <dd>{t(`accounts.kinds.${account.kind}`)}</dd>
        </div>
        <div>
          <dt>{t('accounts.fields.assetCode')}</dt>
          <dd>{account.assetCode}</dd>
        </div>
        <div>
          <dt>{t('accounts.detail.identity.product')}</dt>
          <dd>{originLabel}</dd>
        </div>
        <div>
          <dt>{t('accounts.detail.identity.group')}</dt>
          <dd>{groupLabel}</dd>
        </div>
        <div>
          <dt>{t('accounts.detail.identity.lifecycle')}</dt>
          <dd>
            <span>
              {t('accounts.detail.identity.openedOn', {
                date: formatCalendarDay(account.openedOn, i18n.language),
              })}
            </span>
            {account.closedOn ? (
              <span>
                {t('accounts.detail.identity.closedOn', {
                  date: formatCalendarDay(account.closedOn, i18n.language),
                })}
              </span>
            ) : null}
            {account.archivedAt ? (
              <span>
                {t('accounts.detail.identity.archivedOn', {
                  date: formatCalendarDay(account.archivedAt.slice(0, 10), i18n.language),
                })}
              </span>
            ) : null}
          </dd>
        </div>
        <div className={styles.valuation}>
          <dt>{t('accounts.fields.balance')}</dt>
          <dd>
            <AccountValuationCell valuation={account.valuation} />
          </dd>
        </div>
      </dl>
    </div>
  );
}
