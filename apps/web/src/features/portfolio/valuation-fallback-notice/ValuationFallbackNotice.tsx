import type { PortfolioOverviewValuation } from '@cadran/api-client';
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
  if (!selected || !newerPartial) return null;
  return (
    <p className={styles.notice}>
      Valorisation du {formatCalendarNumericDay(newerPartial.valuedOn)} partielle : valeurs du{' '}
      {formatCalendarNumericDay(selected.valuedOn)} affichées.
    </p>
  );
}
