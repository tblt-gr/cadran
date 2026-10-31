import type { PortfolioOverviewAccountCard } from '@cadran/api-client';
import { StatusBadge } from '@/components/ui/status-badge/StatusBadge';
import { formatCalendarNumericDay } from '@/lib/decimal';
import styles from './ValuationQuality.module.css';

/** The one reminder worth a glance, by priority: an incomplete value, an old one, a month end to type. */
function reminder(account: PortfolioOverviewAccountCard): string | null {
  const valuation = account.latestValuation;
  if (valuation?.completeness === 'PARTIAL') return 'Valorisation partielle';
  if (valuation?.stale) return `Valorisation ancienne (${valuation.ageDays} jours)`;
  if (account.dueMonths.length > 0) return 'Fin de mois à saisir';
  return null;
}

/** Date of the latest valuation and, only when something needs attention, a single reminder. */
export function ValuationQuality({ account }: { account: PortfolioOverviewAccountCard }) {
  const valuation = account.latestValuation;
  const message = reminder(account);
  if (!valuation && !message) return null;

  return (
    <div className={styles.line}>
      {valuation ? <p>au {formatCalendarNumericDay(valuation.valuedOn)}</p> : null}
      {message ? (
        <StatusBadge icon="alert" tone="warning">
          {message}
        </StatusBadge>
      ) : null}
    </div>
  );
}
