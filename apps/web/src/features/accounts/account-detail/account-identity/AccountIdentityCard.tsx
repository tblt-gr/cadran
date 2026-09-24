import type { Account } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { useProductOptions } from '@/features/accounts/account-wizard/useProductOptions';
import { useTemplateOptions } from '@/features/accounts/account-wizard/useTemplateOptions';
import { rateLayers } from '@/features/accounts/account-rules/ruleLayers';
import { useAccountRules } from '@/features/accounts/account-rules/useAccountRules';
import { AccountValuationCell } from '@/features/accounts/account-valuation-cell/AccountValuationCell';
import { depositCeilingOf } from '@/lib/depositCeiling';
import { formatCalendarDay } from '@/lib/decimal';
import { todayInBrowser } from '@/lib/businessDay';
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
  const group = useAccountGroupLabel(account.primaryGroupId);
  const products = useProductOptions(account.valuation.requestedOn);
  const templates = useTemplateOptions();
  const ceiling = depositCeilingOf(account, products.items, templates.items);

  // The rate rules in force today: a screen for reading the account, not for
  // choosing a date, so it always echoes the current business day rather than
  // exposing the date picker the rules panel already offers.
  const rules = useAccountRules(account.id, todayInBrowser());
  const rulesData = rules.data ?? null;
  const headlineRate =
    rulesData === null
      ? null
      : (rulesData.rates.find((rate) => rate.kind === 'ANNUAL_RATE') ?? rulesData.rates[0] ?? null);
  const headlineRateMeasure =
    headlineRate === null || rulesData === null
      ? null
      : rateLayers(headlineRate, rulesData.assetCode).find(
          (layer) => layer.layer === headlineRate.effectiveLayer,
        );

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
      <div className={styles.top}>
        <div className={styles.identity}>
          <p>{t('accounts.detail.identity.title')}</p>
          <h3>{account.label}</h3>
          <span>
            {[account.institution, account.maskedIdentifier && `••${account.maskedIdentifier}`]
              .filter(Boolean)
              .join(' · ') || t('accounts.detail.identity.noInstitution')}
          </span>
          <span className={styles.meta}>{t(`accounts.kinds.${account.kind}`)}</span>
          <span className={styles.meta}>{groupLabel}</span>
          <StatusBadge tone={STATUS_TONE[account.status]}>
            {t(`accounts.statuses.${account.status}`)}
          </StatusBadge>
        </div>

        <div className={styles.balancePanel}>
          <span className={styles.balanceLabel}>{t('accounts.fields.balance')}</span>
          <AccountValuationCell ceiling={ceiling} size="lg" valuation={account.valuation} />
          <span className={styles.openedOn}>
            {t('accounts.detail.identity.openedOn', {
              date: formatCalendarDay(account.openedOn, i18n.language),
            })}
          </span>
          {headlineRate && headlineRateMeasure ? (
            <div className={styles.rateStat}>
              <span className={styles.balanceLabel}>
                {t(`catalog.rules.kinds.${headlineRate.kind}`)}
              </span>
              <div>{headlineRateMeasure.value}</div>
              <div className={styles.rateMeasure}>{headlineRateMeasure.measure}</div>
            </div>
          ) : null}
        </div>
      </div>

      {account.closedOn || account.archivedAt ? (
        <dl className={styles.list}>
          <div>
            <dt>{t('accounts.detail.identity.lifecycle')}</dt>
            <dd>
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
        </dl>
      ) : null}
    </div>
  );
}
