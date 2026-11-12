import { useHeaderActionsSlot } from './HeaderActionsContext';
import type { ReactNode, Ref } from 'react';
import styles from './Header.module.css';

interface HeaderProps {
  accountSlot?: ReactNode;
  /** Where the page sits; rendered on the left of the bar, which stays pinned while the page scrolls. */
  breadcrumb?: ReactNode;
  freshnessLabel?: string;
  headerDate?: string;
  /** Present when the label names a problem the user can open and fix. */
  onFreshnessClick?: () => void;
  title: string;
  /** Receives focus after a navigation, so keyboard and screen reader users land on the new page. */
  titleRef?: Ref<HTMLHeadingElement>;
  subtitle?: string;
}

/**
 * Two rows: a thin bar pinned to the top (where the page is, how fresh it is, what it offers) and
 * the page title below it, which scrolls away with the content.
 */
export function Header({
  accountSlot,
  breadcrumb,
  freshnessLabel,
  headerDate,
  onFreshnessClick,
  title,
  titleRef,
  subtitle,
}: HeaderProps) {
  const setActionsSlot = useHeaderActionsSlot();

  return (
    <>
      <header className={styles.bar}>
        <div className={styles.trail}>{breadcrumb}</div>
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
      <div className={styles.titleBlock}>
        {headerDate ? (
          <p className={styles.date} data-visual-mask>
            {headerDate}
          </p>
        ) : null}
        <h1 ref={titleRef} tabIndex={-1}>
          {title}
        </h1>
        {subtitle ? <p className={styles.subtitle}>{subtitle}</p> : null}
      </div>
    </>
  );
}
