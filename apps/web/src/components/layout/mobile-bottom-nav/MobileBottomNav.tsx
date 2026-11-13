import { useTranslation } from 'react-i18next';
import { navigationItems } from '@/lib/navigation';
import {
  NavigationLink,
  type NavigationHandler,
} from '@/components/layout/navigation-link/NavigationLink';
import styles from './MobileBottomNav.module.css';

/** Five icons share a phone's width without labels: the glyph can be large enough to touch. */
const MOBILE_ICON_SIZE = 22;

interface MobileBottomNavProps {
  onNavigate: NavigationHandler;
  onNavigationIntent?: (href: string) => void;
  path: string;
}

export function MobileBottomNav({ onNavigate, onNavigationIntent, path }: MobileBottomNavProps) {
  const { t } = useTranslation();

  return (
    <nav aria-label={t('navigation.mobileLabel')} className={styles.navigation}>
      {navigationItems.map((item) => (
        <NavigationLink
          className={styles.link}
          hideLabel
          iconSize={MOBILE_ICON_SIZE}
          item={item}
          key={item.href}
          onIntent={onNavigationIntent}
          onNavigate={onNavigate}
          path={path}
        />
      ))}
    </nav>
  );
}
