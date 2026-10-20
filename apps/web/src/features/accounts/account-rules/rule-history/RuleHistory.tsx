import type { AccountRuleClaim, AccountRuleOverride, ProductRuleKind } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { EmptyValue } from '@/components/ui/empty-value/EmptyValue';
import { ruleTextKey } from '@/features/catalog-rules/ruleText';
import { RulePeriod } from '@/features/catalog-rules/rule-provenance/RuleProvenance';
import { formatAmount, formatCalendarDay, formatPercentDecimal } from '@/lib/decimal';
import styles from './RuleHistory.module.css';

interface RuleHistoryProps {
  /** The override already read as the value in force: not repeated here. */
  inForceId: string | null;
  kind: ProductRuleKind;
  onWithdraw: (claim: AccountRuleClaim, kind: ProductRuleKind) => void;
  overrides: AccountRuleOverride[];
}

/**
 * The earlier values of one rule on this account, standing or withdrawn.
 *
 * A withdrawn claim is kept and shown rather than removed: it is the
 * provenance of every past statement produced while it applied. It is marked
 * in words, not by a shade. A rule never overridden, or whose only override is
 * the one in force, has no history and renders nothing.
 */
export function RuleHistory({ inForceId, kind, onWithdraw, overrides }: RuleHistoryProps) {
  const { i18n, t } = useTranslation();
  const earlier = overrides.filter((override) => override.id !== inForceId);

  if (earlier.length === 0) {
    return null;
  }

  return (
    <details className={styles.history}>
      <summary>{t('accounts.overrides.historyTitle')}</summary>
      <ul className={styles.list}>
        {earlier.map((override) => (
          <li key={override.id}>
            <span className={styles.value}>
              <OverrideValue override={override} />
            </span>
            <small>
              <RulePeriod validFrom={override.validFrom} validTo={override.validTo} />
              {' · '}
              {t(
                override.standing
                  ? 'accounts.overrides.statuses.standing'
                  : 'accounts.overrides.statuses.withdrawn',
              )}
              {override.withdrawnAt === null
                ? null
                : ` ${formatCalendarDay(override.withdrawnAt.slice(0, 10), i18n.language)}`}
            </small>
            {override.reason === '' ? null : <small>{override.reason}</small>}
            {override.standing ? (
              <button
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
                  )
                }
                type="button"
              >
                {t('accounts.rules.withdraw')}
              </button>
            ) : null}
          </li>
        ))}
      </ul>
    </details>
  );
}

/** The value a past claim stated, in the same words as the value in force. */
function OverrideValue({ override }: { override: AccountRuleOverride }) {
  const { i18n, t } = useTranslation();

  if (override.amount !== null) {
    return <>{formatAmount(override.amount.value, override.amount.assetCode, i18n.language)}</>;
  }
  if (override.brackets !== null && override.brackets.length > 0) {
    return (
      <>
        {override.brackets
          .map((bracket) =>
            t('catalog.rules.percentValue', {
              value: formatPercentDecimal(bracket.percentage, i18n.language),
            }),
          )
          .join(' / ')}
      </>
    );
  }
  const wording = override.text === null ? null : ruleTextKey(override.text);
  if (wording !== null) {
    return <>{t(wording)}</>;
  }

  return <EmptyValue label={t('accounts.rules.unavailableValue')} />;
}
