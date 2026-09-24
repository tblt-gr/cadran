import type { AccountRate, AppliedRate } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatAmount, formatDecimal } from '@/lib/decimal';
import styles from './RateMeasure.module.css';

/**
 * The applied reading arrives at the storage scale of 24 fractional digits
 * even when it is an exact round figure, because nothing upstream rounds it
 * the way a balance's `display` companion does. Dropping a fraction that is
 * entirely zero prints the same value it was — this is not the rounding
 * `formatDecimal`/`formatAmount` refuse to do, since no digit that could
 * change the value is ever touched, only the zeros that cannot.
 */
function withoutZeroFraction(value: string): string {
  const separator = value.indexOf('.');
  if (separator === -1) {
    return value;
  }

  return /^0*$/.test(value.slice(separator + 1)) ? value.slice(0, separator) : value;
}

interface RateMeasureProps {
  applied: AppliedRate | null;
  assetCode: string;
  rate: AccountRate;
  showApplied: boolean;
}

/**
 * How the scale combines over an amount, whether the rate is owed to the
 * holder at all, and — on the winning layer — how the latest recorded
 * balance reads the scale.
 *
 * A revisable rate is only the rate published today and must never read as
 * an acquired return. The applied reading is owned by the API: the screen
 * never multiplies a typed example.
 */
export function RateMeasure({ applied, assetCode, rate, showApplied }: RateMeasureProps) {
  const { i18n, t } = useTranslation();

  return (
    <>
      {/* A single bracket covers every amount, so both application modes agree
          on it and naming one would suggest a choice the scale does not make. */}
      {rate.brackets.length > 1 ? (
        <span>{t(`accounts.rules.applications.${rate.application}`)}</span>
      ) : null}
      <small>{t(rate.guaranteed ? 'accounts.rules.guaranteed' : 'accounts.rules.revisable')}</small>
      {showApplied ? (
        <AppliedReading applied={applied} assetCode={assetCode} language={i18n.language} />
      ) : null}
    </>
  );
}

function AppliedReading({
  applied,
  assetCode,
  language,
}: {
  applied: AppliedRate | null;
  assetCode: string;
  language: string;
}) {
  const { t } = useTranslation();

  if (applied === null) {
    return <small>{t('accounts.rules.applied.missingValuation')}</small>;
  }

  if (applied.unsettledReason !== null) {
    return <small>{t(`accounts.rules.applied.${applied.unsettledReason}`)}</small>;
  }

  if (applied.effectivePercentage === null || applied.interest === null) {
    return <small>{t('accounts.rules.applied.missingValuation')}</small>;
  }

  return (
    <>
      <small>
        {t('accounts.rules.applied.effective', {
          rate: t('catalog.rules.percentValue', {
            value: formatDecimal(withoutZeroFraction(applied.effectivePercentage), language),
          }),
          interest: formatAmount(withoutZeroFraction(applied.interest), assetCode, language),
        })}
      </small>
      {applied.rateShiftsAboveFirstBracket ? (
        <small className={styles.shifted} role="status">
          {t('accounts.rules.applied.shifted')}
        </small>
      ) : null}
    </>
  );
}
