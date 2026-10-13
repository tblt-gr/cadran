import { useEffect, useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useClientNavigation } from '@/hooks/use-client-navigation';
import { getRouteTitleKey } from '@/lib/navigation';
import { Header } from '@/components/layout/header/Header';
import {
  HeaderTitleProvider,
  useHeaderTitleValue,
} from '@/components/layout/header/HeaderTitleContext';
import { HeaderActionsProvider } from '@/components/layout/header/HeaderActionsContext';
import { MobileBottomNav } from '@/components/layout/mobile-bottom-nav/MobileBottomNav';
import { MobileMoreSheet } from '@/components/layout/mobile-more-sheet/MobileMoreSheet';
import { Sidebar } from '@/components/layout/sidebar/Sidebar';
import styles from './AppShell.module.css';
import { BreadcrumbProvider } from '@/components/layout/breadcrumb/BreadcrumbProvider';
import { RouteBreadcrumb } from '@/components/layout/breadcrumb/RouteBreadcrumb';

interface AppShellProps {
  children: ReactNode;
  accountSlot?: ReactNode;
  contextPanel?: ReactNode;
  freshnessLabel?: string;
  headerDate?: string;
  /** A navigation is waiting for its page: the content region is busy, not frozen. */
  isNavigating?: boolean;
  /** The user shows interest in a destination (hover, focus), so the app can warm it up. */
  onNavigationIntent?: (href: string) => void;
  path: string;
  setPath: (path: string) => void;
}

export function AppShell(props: AppShellProps) {
  return (
    <HeaderTitleProvider>
      <BreadcrumbProvider>
        <HeaderActionsProvider>
          <AppShellContent {...props} />
        </HeaderActionsProvider>
      </BreadcrumbProvider>
    </HeaderTitleProvider>
  );
}

function AppShellContent({
  children,
  accountSlot,
  contextPanel,
  freshnessLabel,
  headerDate,
  isNavigating = false,
  onNavigationIntent,
  path,
  setPath,
}: AppShellProps) {
  const { t } = useTranslation();
  const [isCollapsed, setIsCollapsed] = useState(false);
  const [isMoreOpen, setIsMoreOpen] = useState(false);
  const moreButton = useRef<HTMLButtonElement>(null);
  const headerOverride = useHeaderTitleValue(path);
  const title = headerOverride ?? t(getRouteTitleKey(path));
  const { handleNavigate, navigate } = useClientNavigation(setPath);

  useEffect(() => {
    document.title = path === '/' ? t('app.name') : `${title} · ${t('app.name')}`;
  }, [path, title, t]);

  function closeMoreSheet() {
    setIsMoreOpen(false);
    window.requestAnimationFrame(() => moreButton.current?.focus());
  }

  return (
    <div className={`${styles.shell}${isCollapsed ? ` ${styles.collapsed}` : ''}`} data-app-shell>
      <a className={styles.skipLink} href="#main-content">
        {t('accessibility.skipToContent')}
      </a>

      <Sidebar
        isCollapsed={isCollapsed}
        onNavigate={handleNavigate}
        onNavigationIntent={onNavigationIntent}
        onToggle={() => setIsCollapsed((value) => !value)}
        path={path}
      />

      <div className={styles.workspace}>
        <Header
          accountSlot={accountSlot}
          freshnessLabel={freshnessLabel}
          headerDate={headerDate}
          title={title}
        />

        <div className={`${styles.body}${contextPanel ? ` ${styles.bodyWithPanel}` : ''}`}>
          <main aria-busy={isNavigating || undefined} id="main-content" tabIndex={-1}>
            <RouteBreadcrumb path={path} onNavigate={handleNavigate} />
            {children}
          </main>
          {contextPanel}
        </div>
      </div>

      <MobileBottomNav
        isMoreOpen={isMoreOpen}
        moreButton={moreButton}
        onNavigate={handleNavigate}
        onNavigationIntent={onNavigationIntent}
        onOpenMore={() => setIsMoreOpen(true)}
        path={path}
      />

      {isMoreOpen ? (
        <MobileMoreSheet
          accountSlot={accountSlot}
          close={closeMoreSheet}
          navigate={navigate}
          onNavigationIntent={onNavigationIntent}
          path={path}
        />
      ) : null}
    </div>
  );
}
