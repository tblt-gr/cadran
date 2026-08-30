import { useTranslation } from 'react-i18next';
import type { FoundationAction } from '../../../features/foundation/FoundationActionSheet';
import { Icon } from '../../ui/icon/Icon';
import styles from './Header.module.css';

interface HeaderProps {
  freshnessLabel: string;
  headerDate: string;
  onAction: (action: FoundationAction, trigger: HTMLButtonElement) => void;
  title: string;
}

export function Header({ freshnessLabel, headerDate, onAction, title }: HeaderProps) {
  const { t } = useTranslation();

  return (
    <header className={styles.header}>
      <div>
        <p className={styles.date}>{headerDate}</p>
        <h1>{title}</h1>
      </div>
      <div className={styles.actions}>
        <span className={styles.freshness}>
          <span className={styles.freshnessDot} />
          {freshnessLabel}
        </span>
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
      </div>
    </header>
  );
}
