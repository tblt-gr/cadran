import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import type { FoundationAction } from '@/features/foundation/FoundationActionSheet';
import { Icon } from '@/components/ui/icon/Icon';
import styles from './Header.module.css';

interface HeaderProps {
  accountSlot?: ReactNode;
  freshnessLabel?: string;
  headerDate?: string;
  onAction: (action: FoundationAction, trigger: HTMLButtonElement) => void;
  showGlobalActions?: boolean;
  title: string;
}

export function Header({
  accountSlot,
  freshnessLabel,
  headerDate,
  onAction,
  showGlobalActions = true,
  title,
}: HeaderProps) {
  const { t } = useTranslation();

  return (
    <header className={styles.header}>
      <div>
        {headerDate ? <p className={styles.date}>{headerDate}</p> : null}
        <h1>{title}</h1>
      </div>
      <div className={styles.actions}>
        {showGlobalActions ? (
          <>
            {freshnessLabel ? (
              <span className={styles.freshness}>
                <span className={styles.freshnessDot} />
                {freshnessLabel}
              </span>
            ) : null}
            <button
              aria-label={t('actions.search')}
              className="icon-button"
              onClick={(event) => onAction('search', event.currentTarget)}
              type="button"
            >
              <Icon name="search" />
            </button>
            <button
              className="primary-action"
              onClick={(event) => onAction('add', event.currentTarget)}
              type="button"
            >
              <Icon name="add" size={18} />
              {t('actions.add')}
            </button>
          </>
        ) : null}
        {accountSlot}
      </div>
    </header>
  );
}
