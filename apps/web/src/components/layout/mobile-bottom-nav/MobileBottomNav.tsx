import { useTranslation } from 'react-i18next';
import type { RefObject } from 'react';
import type { FoundationAction } from '@/features/foundation/FoundationActionSheet';
import { navigationItems } from '@/lib/navigation';
import { Icon } from '@/components/ui/icon/Icon';
import {
  NavigationLink,
  type NavigationHandler,
} from '@/components/layout/navigation-link/NavigationLink';
import styles from './MobileBottomNav.module.css';

interface MobileBottomNavProps {
  isMoreOpen: boolean;
  moreButton: RefObject<HTMLButtonElement | null>;
  onAction: (action: FoundationAction, trigger: HTMLButtonElement) => void;
  onNavigate: NavigationHandler;
  onOpenMore: () => void;
  path: string;
  showGlobalActions?: boolean;
}

export function MobileBottomNav({
  isMoreOpen,
  moreButton,
  onAction,
  onNavigate,
  onOpenMore,
  path,
  showGlobalActions = true,
}: MobileBottomNavProps) {
  const { t } = useTranslation();
  const mobileItems = navigationItems.filter((item) => item.mobile);
  const isMoreCurrent = navigationItems.some((item) => !item.mobile && item.match(path));

  return (
    <nav
      aria-label={t('navigation.mobileLabel')}
      className={`${styles.navigation}${showGlobalActions ? '' : ` ${styles.withoutAction}`}`}
    >
      {mobileItems.slice(0, 2).map((item) => (
        <NavigationLink
          className={styles.link}
          item={item}
          key={item.href}
          onNavigate={onNavigate}
          path={path}
        />
      ))}
      {showGlobalActions ? (
        <button
          aria-label={t('actions.add')}
          className={styles.add}
          onClick={(event) => onAction('add', event.currentTarget)}
          type="button"
        >
          <Icon name="add" size={24} />
          <span>{t('actions.add')}</span>
        </button>
      ) : null}
      {mobileItems.slice(2).map((item) => (
        <NavigationLink
          className={styles.link}
          item={item}
          key={item.href}
          onNavigate={onNavigate}
          path={path}
        />
      ))}
      <button
        aria-current={isMoreCurrent ? 'page' : undefined}
        aria-expanded={isMoreOpen}
        aria-haspopup="dialog"
        className={styles.link}
        onClick={onOpenMore}
        ref={moreButton}
        type="button"
      >
        <Icon name="more" />
        <span>{t('actions.more')}</span>
      </button>
    </nav>
  );
}
