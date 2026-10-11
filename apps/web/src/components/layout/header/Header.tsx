import { useHeaderActionsSlot } from './HeaderActionsContext';
import type { ReactNode } from 'react';
import styles from './Header.module.css';

interface HeaderProps {
  accountSlot?: ReactNode;
  freshnessLabel?: string;
  headerDate?: string;
  title: string;
}

export function Header({ accountSlot, freshnessLabel, headerDate, title }: HeaderProps) {
  const setActionsSlot = useHeaderActionsSlot();

  return (
    <header className={styles.header}>
      <div>
        {headerDate ? <p className={styles.date}>{headerDate}</p> : null}
        <h1>{title}</h1>
      </div>
      <div className={styles.actions}>
        {freshnessLabel ? (
          <span className={styles.freshness}>
            <span className={styles.freshnessDot} />
            {freshnessLabel}
          </span>
        ) : null}
        <div className={styles.pageActions} ref={setActionsSlot} />
        {accountSlot}
      </div>
    </header>
  );
}
