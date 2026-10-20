import type { ProductRuleKind } from '@cadran/api-client';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { InfoButton } from '@/components/ui/info-button/InfoButton';
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
 * Not a zero and not an empty value: the value is unknown for the date, and
 * the reason stays on the line. A local claim is exactly how the gap is
 * filled, so the override action sits behind the details button like every
 * other rule's.
 */
export function UnavailableRuleItem({
  asOf,
  focusAction,
  kind,
  onOverride,
}: UnavailableRuleItemProps) {
  const { i18n, t } = useTranslation();
  const headingId = useId();
  const detailsId = useId();
  const [open, setOpen] = useState(false);
  const name = t(`catalog.rules.kinds.${kind}`);

  return (
    <li>
      <section aria-labelledby={headingId} className={styles.rule}>
        <header className={styles.heading}>
          <h4 id={headingId}>{name}</h4>
          <InfoButton
            aria-controls={detailsId}
            aria-expanded={open}
            data-autofocus={focusAction || undefined}
            label={t('accounts.rules.detailsOf', { rule: name })}
            onClick={() => setOpen((current) => !current)}
          />
        </header>
        <p className={styles.unavailable}>
          {t('accounts.rules.unavailable', { date: formatCalendarDay(asOf, i18n.language) })}
        </p>
        {open ? (
          <div className={styles.details} id={detailsId}>
            <button className="secondary-action" onClick={() => onOverride(kind)} type="button">
              {t('accounts.rules.override')}
            </button>
          </div>
        ) : null}
      </section>
    </li>
  );
}
