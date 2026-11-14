import type { ReactNode } from 'react';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import { monthHref } from '@/features/budget/monthly-budget/budgetPeriod';
import styles from './MonthLink.module.css';

/** A month of a report, linking to that month's budget. It reads as plain text until hovered. */
export function MonthLink({ children, month }: { children: ReactNode; month: string }) {
  const href = monthHref(month, null);
  return (
    <a className={styles.link} href={href} onClick={(event) => handleClientNavigation(event, href)}>
      {children}
    </a>
  );
}
