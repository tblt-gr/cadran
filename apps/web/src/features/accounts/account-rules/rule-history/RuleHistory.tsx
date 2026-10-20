import type { AccountRuleClaim, AccountRuleOverride, ProductRuleKind } from '@cadran/api-client';
import type { TFunction } from 'i18next';
import { useTranslation } from 'react-i18next';
import { ruleTextKey } from '@/features/catalog-rules/ruleText';
import { formatAmount, formatCalendarDay, formatPercentDecimal } from '@/lib/decimal';
import styles from './RuleHistory.module.css';

interface RuleHistoryProps {
  /** The override read as the value in force: listed, and marked in words. */
  inForceId: string | null;
  kind: ProductRuleKind;
  /** The rule's name, to say which claim a withdrawal is about. */
  name: string;
  /** `description` names the claim (rule, value, period) for the confirmation. */
  onWithdraw: (claim: AccountRuleClaim, kind: ProductRuleKind, description: string) => void;
  overrides: AccountRuleOverride[];
}

/**
 * Every claim ever recorded on one rule of this account, newest period first:
 * the one in force, past and future ones, and withdrawn ones.
 *
 * A withdrawn claim is kept and shown rather than removed: it is the
 * provenance of every past statement produced while it applied. Each status is
 * written in words, not carried by a shade. A rule never overridden has no
 * history and renders nothing. Each claim that can still be withdrawn carries
 * its own button, named after its value and period, so no withdrawal is
 * ambiguous.
 */
export function RuleHistory({ inForceId, kind, name, onWithdraw, overrides }: RuleHistoryProps) {
  const { i18n, t } = useTranslation();
  const entries = [...overrides].sort(
    (a, b) => b.validFrom.localeCompare(a.validFrom) || b.recordedAt.localeCompare(a.recordedAt),
  );

  if (entries.length === 0) {
    return null;
  }

  return (
    <details className={styles.history}>
      <summary>{t('accounts.overrides.historyTitle')}</summary>
      <ul className={styles.list}>
        {entries.map((override) => {
          const value = overrideValueText(override, i18n.language, t);
          const period = periodText(override, i18n.language, t);

          return (
            <li key={override.id}>
              <span className={styles.value}>{value}</span>
              <small>{period}</small>
              {override.reason === '' ? null : <small>{override.reason}</small>}
              <small>
                {t(
                  override.id === inForceId
                    ? 'accounts.overrides.statuses.standing'
                    : override.standing
                      ? 'accounts.overrides.statuses.recorded'
                      : 'accounts.overrides.statuses.withdrawn',
                )}
                {override.withdrawnAt === null
                  ? null
                  : ` ${formatCalendarDay(override.withdrawnAt.slice(0, 10), i18n.language)}`}
              </small>
              {override.standing ? (
                <button
                  aria-label={t('accounts.overrides.withdrawEntry', { value, period })}
                  className={`secondary-action ${styles.action}`}
                  onClick={() =>
                    onWithdraw(
                      {
                        overrideId: override.id,
                        reason: override.reason,
                        authorId: override.authorId,
                        authorDisplayName: override.authorDisplayName,
                        recordedAt: override.recordedAt,
                      },
                      kind,
                      t('accounts.overrides.withdrawSubject', { rule: name, value, period }),
                    )
                  }
                  type="button"
                >
                  {t('accounts.overrides.withdrawShort')}
                </button>
              ) : null}
            </li>
          );
        })}
      </ul>
    </details>
  );
}

function periodText(override: AccountRuleOverride, language: string, t: TFunction): string {
  const from = formatCalendarDay(override.validFrom, language);

  return override.validTo === null
    ? t('catalog.rules.openPeriod', { from })
    : t('catalog.rules.closedPeriod', { from, to: formatCalendarDay(override.validTo, language) });
}

/** The value a claim stated, in the same words as the value in force. */
function overrideValueText(override: AccountRuleOverride, language: string, t: TFunction): string {
  if (override.amount !== null) {
    return formatAmount(override.amount.value, override.amount.assetCode, language);
  }
  if (override.brackets !== null && override.brackets.length > 0) {
    return override.brackets
      .map((bracket) =>
        t('catalog.rules.percentValue', {
          value: formatPercentDecimal(bracket.percentage, language),
        }),
      )
      .join(' / ');
  }
  const wording = override.text === null ? null : ruleTextKey(override.text);

  return wording === null ? t('accounts.rules.unavailableValue') : t(wording);
}
