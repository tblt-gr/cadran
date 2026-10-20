import type { ProductRuleKind } from '@cadran/api-client';
import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import styles from '@/features/accounts/account-rules/account-rule-item/AccountRuleItem.module.css';
import { formatCalendarDay } from '@/lib/decimal';

interface UnavailableRuleItemProps {
  asOf: string;
  focusAction: boolean;
  kind: ProductRuleKind;
  onOverride: (kind: ProductRuleKind) => void;
}

/**
 * A rule the account is expected to carry and no layer covers on this date.
 *
 * Not a zero and not an empty cell: the value is unknown for the date, and the
 * reason travels with the rule. A local claim is exactly how the gap is filled,
 * so the override action stays on it.
 */
export function UnavailableRuleItem({
  asOf,
  focusAction,
  kind,
  onOverride,
}: UnavailableRuleItemProps) {
  const { i18n, t } = useTranslation();
  const headingId = useId();

  return (
    <li>
      <section aria-labelledby={headingId} className={styles.rule}>
        <header className={styles.heading}>
          <h4 id={headingId}>{t(`catalog.rules.kinds.${kind}`)}</h4>
          <button
            className="secondary-action"
            data-autofocus={focusAction || undefined}
            onClick={() => onOverride(kind)}
            type="button"
          >
            {t('accounts.rules.override')}
          </button>
        </header>
        <p className={styles.unavailable}>
          {t('accounts.rules.unavailable', { date: formatCalendarDay(asOf, i18n.language) })}
        </p>
      </section>
    </li>
  );
}
