import { useTranslation } from 'react-i18next';
import { navigationItems } from '@/lib/navigation';
import { BrandMark } from '@/components/ui/brand-mark/BrandMark';
import { Icon } from '@/components/ui/icon/Icon';
import {
  NavigationLink,
  type NavigationHandler,
} from '@/components/layout/navigation-link/NavigationLink';
import styles from './Sidebar.module.css';

interface SidebarProps {
  isCollapsed: boolean;
  onNavigate: NavigationHandler;
  onNavigationIntent?: (href: string) => void;
  onToggle: () => void;
  path: string;
}

export function Sidebar({
  isCollapsed,
  onNavigate,
  onNavigationIntent,
  onToggle,
  path,
}: SidebarProps) {
  const { t } = useTranslation();

  return (
    <aside className={`${styles.sidebar}${isCollapsed ? ` ${styles.collapsed}` : ''}`}>
      <div className={styles.brandRow}>
        <div className={styles.brand} role="img" aria-label={t('app.name')}>
          <BrandMark className={styles.brandMark} />
          <span>{t('app.shortName')}</span>
        </div>
        <button
          aria-label={isCollapsed ? t('navigation.expand') : t('navigation.collapse')}
          className={`icon-ghost ${styles.collapse}`}
          onClick={onToggle}
          title={isCollapsed ? t('navigation.expand') : t('navigation.collapse')}
          type="button"
        >
          <Icon name="sidebar" size={16} />
        </button>
      </div>

      <nav aria-label={t('navigation.mainLabel')} className={styles.navigation}>
        {navigationItems.map((item) => (
          <NavigationLink
            className={styles.link}
            item={item}
            key={item.href}
            onIntent={onNavigationIntent}
            onNavigate={onNavigate}
            path={path}
          />
        ))}
      </nav>
    </aside>
  );
}
