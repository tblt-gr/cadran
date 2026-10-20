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
  /** Null for a rule read from a workspace product model: nobody published it. */
  source: ProductRuleSource | null;
  validFrom: RuleValidFrom;
  validTo: RuleValidTo;
  /** Null alongside `source`, for the same reason. */
  verification: RuleVerification | null;
}

interface RulePeriodProps {
  validFrom: RuleValidFrom;
  validTo: RuleValidTo;
}

/** An open end is in force for every later date, not an expiry. */
export function RulePeriod({ validFrom, validTo }: RulePeriodProps) {
  const { i18n, t } = useTranslation();
  const from = formatCalendarDay(validFrom, i18n.language);

  return validTo === null
    ? t('catalog.rules.openPeriod', { from })
    : t('catalog.rules.closedPeriod', { from, to: formatCalendarDay(validTo, i18n.language) });
}

/** Null verification means declared by the workspace: nobody published it, so no freshness is graded. */
export function RuleVerificationBadge({ verification }: { verification: RuleVerification | null }) {
  const { t } = useTranslation();

  return verification === null ? (
    <StatusBadge tone="info">{t('accounts.rules.declared')}</StatusBadge>
  ) : (
    <StatusBadge tone={verificationTone[verification]}>
      {t(`catalog.verification.${verification}`)}
    </StatusBadge>
  );
}

/** The publication a rule was read from, as a link and its trace; the one place a source URL is rendered. */
export function RuleSourceReading({ source }: { source: ProductRuleSource | null }) {
  const { i18n, t } = useTranslation();

  if (source === null) {
    return <span>{t('accounts.rules.noSource')}</span>;
  }

  return (
    <>
      <a href={source.url} rel="noreferrer noopener" target="_blank">
        {source.title}
      </a>
      <small>
        {t('catalog.source.trace', {
          publisher: source.publisher,
          retrieved: formatCalendarDay(source.retrievedOn, i18n.language),
        })}
      </small>
    </>
  );
}

/**
 * The three cells every account rule carries: the period it covers, how
 * fresh its verification is and the publication it was read from — or, for a
 * rule read from a workspace's own product model, that it was declared by
 * the workspace and grades no freshness, since nobody published it.
 *
 * A figure without its period and its publication cannot be checked by the
 * holder, so the product catalogue and the rules of an account render this
 * trace from one place: a rule read on either screen shows the same period,
 * the same freshness and the same link. The cells carry no class of their own
 * and inherit the styles of whichever table hosts them.
 */
export function RuleProvenance({ source, validFrom, validTo, verification }: RuleProvenanceProps) {
  return (
    <>
      <td>
        <RulePeriod validFrom={validFrom} validTo={validTo} />
      </td>
      <td>
        <RuleVerificationBadge verification={verification} />
      </td>
      <td>
        <RuleSourceReading source={source} />
      </td>
    </>
  );
}
