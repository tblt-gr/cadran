import type { PortfolioOverviewValuation } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { formatCalendarNumericDay } from '@/lib/decimal';
import styles from './ValuationFallbackNotice.module.css';

/** Keeps the newer incomplete observation visible without dating known values as current. */
export function ValuationFallbackNotice({
  selected,
  newerPartial,
}: {
  selected: PortfolioOverviewValuation | null;
  newerPartial: PortfolioOverviewValuation | null;
}) {
  const { t } = useTranslation();
  if (!selected || !newerPartial) return null;
  return (
    <p className={styles.notice}>
      {t('portfolio.valuations.fallbackNotice', {
        partial: formatCalendarNumericDay(newerPartial.valuedOn),
        shown: formatCalendarNumericDay(selected.valuedOn),
      })}
    </p>
  );
}
