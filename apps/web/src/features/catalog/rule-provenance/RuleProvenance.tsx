import type {
  ProductRuleSource,
  RuleValidFrom,
  RuleValidTo,
  RuleVerification,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatCalendarDay } from '@/lib/decimal';

const verificationTone = {
  VERIFIED: 'positive',
  STALE: 'warning',
  UNVERIFIED: 'info',
} as const;

interface RuleProvenanceProps {
  source: ProductRuleSource;
  validFrom: RuleValidFrom;
  validTo: RuleValidTo;
  verification: RuleVerification;
}

/**
 * The three cells every sourced catalogue rule carries: the period it covers,
 * how fresh its verification is and the publication it was read from.
 *
 * A figure without its period and its publication cannot be checked by the
 * holder, so the product catalogue and the rules of an account render this
 * trace from one place: a rule read on either screen shows the same period,
 * the same freshness and the same link. The cells carry no class of their own
 * and inherit the styles of whichever table hosts them.
 */
export function RuleProvenance({ source, validFrom, validTo, verification }: RuleProvenanceProps) {
  const { i18n, t } = useTranslation();
  const from = formatCalendarDay(validFrom, i18n.language);

  return (
    <>
      <td>
        {/* An open end is in force for every later date, not an expiry. */}
        {validTo === null
          ? t('catalog.rules.openPeriod', { from })
          : t('catalog.rules.closedPeriod', {
              from,
              to: formatCalendarDay(validTo, i18n.language),
            })}
      </td>
      <td>
        <StatusBadge tone={verificationTone[verification]}>
          {t(`catalog.verification.${verification}`)}
        </StatusBadge>
      </td>
      <td>
        <a href={source.url} rel="noreferrer noopener" target="_blank">
          {source.title}
        </a>
        <small>
          {t('catalog.source.trace', {
            publisher: source.publisher,
            retrieved: formatCalendarDay(source.retrievedOn, i18n.language),
          })}
        </small>
      </td>
    </>
  );
}
