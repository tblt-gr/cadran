import { useTranslation } from 'react-i18next';
import { navigationItems } from '@/lib/navigation';
import { Icon } from '@/components/ui/icon/Icon';
import {
  NavigationLink,
  type NavigationHandler,
} from '@/components/layout/navigation-link/NavigationLink';
import styles from './Sidebar.module.css';

interface SidebarProps {
  isCollapsed: boolean;
  onNavigate: NavigationHandler;
  onToggle: () => void;
  path: string;
}

export function Sidebar({ isCollapsed, onNavigate, onToggle, path }: SidebarProps) {
  const { t } = useTranslation();

  return (
    <aside className={`${styles.sidebar}${isCollapsed ? ` ${styles.collapsed}` : ''}`}>
      <div className={styles.brand} aria-label={t('app.name')}>
        <svg aria-hidden="true" className={styles.brandMark} viewBox="0 0 40 40">
          <circle cx="20" cy="20" fill="none" r="15" stroke="currentColor" strokeWidth="2" />
          <path
            d="M20 8v5M20 27v5M8 20h5M27 20h5M20 20l7-7"
            stroke="currentColor"
            strokeLinecap="round"
            strokeWidth="2"
          />
          <circle cx="20" cy="20" fill="currentColor" r="2.5" />
        </svg>
        <span>{t('app.shortName')}</span>
      </div>

      <nav aria-label={t('navigation.mainLabel')} className={styles.navigation}>
        {navigationItems.map((item) => (
          <NavigationLink
            className={styles.link}
            item={item}
            key={item.href}
            onNavigate={onNavigate}
            path={path}
          />
        ))}
      </nav>

      <button
        aria-label={isCollapsed ? t('navigation.expand') : t('navigation.collapse')}
        className={styles.collapse}
        onClick={onToggle}
        type="button"
      >
        <Icon name={isCollapsed ? 'chevron-right' : 'chevron-left'} />
        <span>{isCollapsed ? t('actions.expand') : t('actions.collapse')}</span>
      </button>
    </aside>
  );
}
