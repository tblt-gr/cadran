import type { PeriodClosingBlocker, PeriodClosingCondition } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './ClosureBlockerList.module.css';

/** Where each blocking condition is resolved; `period` is `YYYY-MM`. */
export function closureBlockerHref(condition: PeriodClosingCondition, period: string): string {
  if (condition !== 'PENDING_TRANSACTIONS') return '/accounts';

  const [year, month] = period.split('-').map(Number) as [number, number];
  const lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate();
  const params = new URLSearchParams({
    period: 'custom',
    from: `${period}-01`,
    to: `${period}-${String(lastDay).padStart(2, '0')}`,
    state: 'PENDING',
  });

  return `/transactions?${params.toString()}`;
}

interface ClosureBlockerLinkProps {
  condition: PeriodClosingCondition;
  /** Called after the link navigates in place, so a modal can dismiss itself. */
  onNavigate?: () => void;
  period: string;
}

export function ClosureBlockerLink({ condition, onNavigate, period }: ClosureBlockerLinkProps) {
  const { t } = useTranslation();
  const href = closureBlockerHref(condition, period);

  return (
    <a
      className={styles.link}
      href={href}
      onClick={(event) => {
        if (handleClientNavigation(event, href)) onNavigate?.();
      }}
    >
      {t(`closures.conditions.${condition}.link`)}
    </a>
  );
}

interface ClosureBlockerListProps {
  blockers: PeriodClosingBlocker[];
  onNavigate?: () => void;
  period: string;
}

/** Says what blocks a closing and links to the screen that resolves each point. */
export function ClosureBlockerList({ blockers, onNavigate, period }: ClosureBlockerListProps) {
  const { t } = useTranslation();
  if (blockers.length === 0) return null;

  return (
    <ul className={styles.list}>
      {blockers.map(({ condition, count }) => (
        <li className={styles.item} key={condition}>
          <span>{t(`closures.conditions.${condition}.summary`, { count })}</span>
          <ClosureBlockerLink condition={condition} onNavigate={onNavigate} period={period} />
        </li>
      ))}
    </ul>
  );
}
