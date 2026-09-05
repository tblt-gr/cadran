import type { AccountRate } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';

interface RateMeasureProps {
  rate: AccountRate;
}

/**
 * How the scale combines over an amount, and whether the rate is owed to the
 * holder at all. A revisable rate is only the rate published today and must
 * never read as an acquired return, whichever authority stated it.
 */
export function RateMeasure({ rate }: RateMeasureProps) {
  const { t } = useTranslation();

  return (
    <>
      {/* A single bracket covers every amount, so both application modes agree
          on it and naming one would suggest a choice the scale does not make. */}
      {rate.brackets.length > 1 ? (
        <span>{t(`accounts.rules.applications.${rate.application}`)}</span>
      ) : null}
      <small>{t(rate.guaranteed ? 'accounts.rules.guaranteed' : 'accounts.rules.revisable')}</small>
    </>
  );
}
