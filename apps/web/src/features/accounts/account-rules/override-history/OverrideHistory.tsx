import type { AccountRuleOverride } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { RulePeriod } from '@/features/catalog-rules/rule-provenance/RuleProvenance';
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
 * withdrawn instead, in words and not by a shade. The list sits under a named
 * disclosure so the rules in force stay the main reading path; the modal body
 * is the only thing that scrolls.
 */
export function OverrideHistory({ overrides }: OverrideHistoryProps) {
  const { i18n, t } = useTranslation();

  // No claim was ever recorded: an empty accordion would only be noise.
  if (overrides.length === 0) {
    return null;
  }

  return (
    <details className={styles.history}>
      <summary>{t('accounts.overrides.historyTitle')}</summary>
      <ul className={styles.list}>
        {overrides.map((override) => (
          <li key={override.id}>
            <dl className={styles.reading}>
              <div>
                <dt>{t('accounts.rules.fields.rule')}</dt>
                <dd>{t(`catalog.rules.kinds.${override.kind}`)}</dd>
              </div>
              <div>
                <dt>{t('accounts.rules.fields.validity')}</dt>
                <dd>
                  <RulePeriod validFrom={override.validFrom} validTo={override.validTo} />
                </dd>
              </div>
              <div>
                <dt>{t('accounts.overrides.fields.status')}</dt>
                <dd>
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
                </dd>
              </div>
              <div>
                <dt>{t('accounts.overrides.fields.author')}</dt>
                <dd>{override.authorDisplayName ?? t('accounts.overrides.unknownAuthor')}</dd>
              </div>
              <div>
                <dt>{t('accounts.overrides.fields.reason')}</dt>
                <dd>
                  {override.reason}
                  <small>
                    {t('accounts.rules.claimedOn', {
                      date: formatCalendarDay(override.recordedAt.slice(0, 10), i18n.language),
                    })}
                  </small>
                </dd>
              </div>
            </dl>
          </li>
        ))}
      </ul>
    </details>
  );
}
