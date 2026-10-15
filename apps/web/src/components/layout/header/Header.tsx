import { useHeaderActionsSlot } from './HeaderActionsContext';
import type { ReactNode } from 'react';
import styles from './Header.module.css';

interface HeaderProps {
  accountSlot?: ReactNode;
  freshnessLabel?: string;
  headerDate?: string;
  /** Present when the label names a problem the user can open and fix. */
  onFreshnessClick?: () => void;
  title: string;
}

export function Header({
  accountSlot,
  freshnessLabel,
  headerDate,
  onFreshnessClick,
  title,
}: HeaderProps) {
  const setActionsSlot = useHeaderActionsSlot();

  return (
    <header className={styles.header}>
      <div>
        {headerDate ? (
          <p className={styles.date} data-visual-mask>
            {headerDate}
          </p>
        ) : null}
        <h1>{title}</h1>
      </div>
      <div className={styles.actions}>
        {freshnessLabel && onFreshnessClick ? (
          <button
            className={`${styles.freshness} ${styles.freshnessAction}`}
            data-visual-mask
            onClick={onFreshnessClick}
            type="button"
          >
            <span className={styles.freshnessDot} />
            {freshnessLabel}
          </button>
        ) : freshnessLabel ? (
          <span className={styles.freshness} data-visual-mask>
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
