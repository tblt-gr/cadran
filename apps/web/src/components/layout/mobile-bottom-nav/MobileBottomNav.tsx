import { useTranslation } from 'react-i18next';
import type { RefObject } from 'react';
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
  onNavigate: NavigationHandler;
  onNavigationIntent?: (href: string) => void;
  onOpenMore: () => void;
  path: string;
}

export function MobileBottomNav({
  isMoreOpen,
  moreButton,
  onNavigate,
  onNavigationIntent,
  onOpenMore,
  path,
}: MobileBottomNavProps) {
  const { t } = useTranslation();
  const mobileItems = navigationItems.filter((item) => item.mobile);
  const isMoreCurrent = navigationItems.some((item) => !item.mobile && item.match(path));

  return (
    <nav aria-label={t('navigation.mobileLabel')} className={styles.navigation}>
      {mobileItems.map((item) => (
        <NavigationLink
          className={styles.link}
          item={item}
          key={item.href}
          onIntent={onNavigationIntent}
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
