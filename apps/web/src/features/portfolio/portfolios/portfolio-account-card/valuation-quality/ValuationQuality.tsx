import type { PortfolioOverviewAccountCard } from '@cadran/api-client';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { ValuationFallbackNotice } from '@/features/portfolio/valuation-fallback-notice/ValuationFallbackNotice';
import { formatCalendarNumericDay } from '@/lib/decimal';
import styles from './ValuationQuality.module.css';

/** Freshness and quality of the latest valuation, plus the month-end and newer-partial reminders. */
export function ValuationQuality({ account }: { account: PortfolioOverviewAccountCard }) {
  const valuation = account.latestValuation;
  const partial = valuation?.completeness === 'PARTIAL';

  return (
    <div className={styles.quality}>
      {valuation ? (
        <div className={styles.line}>
          <p>au {formatCalendarNumericDay(valuation.valuedOn)}</p>
          <StatusBadge tone={valuation.stale || partial ? 'warning' : 'positive'}>
            {partial
              ? 'Valorisation partielle'
              : valuation.stale
                ? `Valorisation ancienne (${valuation.ageDays} jours)`
                : 'Valorisation complète'}
          </StatusBadge>
        </div>
      ) : null}
      {account.dueMonths.length > 0 ? (
        <StatusBadge icon="alert" tone="warning">
          Fin de mois à saisir
        </StatusBadge>
      ) : null}
      <ValuationFallbackNotice selected={valuation} newerPartial={account.newerPartialValuation} />
    </div>
  );
}
