import type { PortfolioOverviewValuation } from '@cadran/api-client';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
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
    <div className={styles.notice}>
      <StatusBadge tone="warning">Valorisation partielle plus récente</StatusBadge>
      <p>
        Valorisation du {formatCalendarNumericDay(newerPartial.valuedOn)} partielle : dernières
        valeurs complètes du {formatCalendarNumericDay(selected.valuedOn)} affichées.
      </p>
    </div>
  );
}
