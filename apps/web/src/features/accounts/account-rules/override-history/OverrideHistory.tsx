import type { AccountRuleOverride } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatCalendarDay } from '@/lib/decimal';
import styles from './OverrideHistory.module.css';

interface OverrideHistoryProps {
  overrides: AccountRuleOverride[];
}

/**
 * Every claim this account has ever made, standing or withdrawn.
 *
 * A withdrawn claim is kept and shown rather than removed: it is the
 * provenance of every past statement produced while it applied, and deleting
 * it would leave a figure nobody can explain any more. It is marked as
 * withdrawn instead, in words and not by a shade.
 */
export function OverrideHistory({ overrides }: OverrideHistoryProps) {
  const { i18n, t } = useTranslation();

  if (overrides.length === 0) {
    return <p className={styles.empty}>{t('accounts.overrides.historyEmpty')}</p>;
  }

  return (
    <div className={styles.tableScroll}>
      <table>
        <caption className="sr-only">{t('accounts.overrides.historyCaption')}</caption>
        <thead>
          <tr>
            <th scope="col">{t('accounts.rules.columns.rule')}</th>
            <th scope="col">{t('accounts.rules.columns.period')}</th>
            <th scope="col">{t('accounts.overrides.columns.status')}</th>
            <th scope="col">{t('accounts.overrides.columns.reason')}</th>
          </tr>
        </thead>
        <tbody>
          {overrides.map((override) => (
            <tr key={override.id}>
              <th scope="row">{t(`catalog.rules.kinds.${override.kind}`)}</th>
              <td>
                {/* An open end is in force for every later date, not an expiry. */}
                {override.validTo === null
                  ? t('catalog.rules.openPeriod', {
                      from: formatCalendarDay(override.validFrom, i18n.language),
                    })
                  : t('catalog.rules.closedPeriod', {
                      from: formatCalendarDay(override.validFrom, i18n.language),
                      to: formatCalendarDay(override.validTo, i18n.language),
                    })}
              </td>
              <td>
                <StatusBadge tone={override.standing ? 'warning' : 'info'}>
                  {t(
                    override.standing
                      ? 'accounts.overrides.statuses.standing'
                      : 'accounts.overrides.statuses.withdrawn',
                  )}
                </StatusBadge>
                {override.withdrawnAt === null ? null : (
                  <small>
                    {t('accounts.overrides.withdrawnOn', {
                      date: formatCalendarDay(override.withdrawnAt.slice(0, 10), i18n.language),
                    })}
                  </small>
                )}
              </td>
              <td>
                {override.reason}
                <small>
                  {t('accounts.rules.claimedOn', {
                    date: formatCalendarDay(override.recordedAt.slice(0, 10), i18n.language),
                  })}
                </small>
              </td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}
