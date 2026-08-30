import { useRef, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import {
  FoundationActionSheet,
  type FoundationAction,
} from '../../../features/foundation/FoundationActionSheet';
import { useClientNavigation } from '../../../hooks/use-client-navigation';
import { getRouteTitleKey } from '../../../lib/navigation';
import { Header } from '../header/Header';
import { MobileBottomNav } from '../mobile-bottom-nav/MobileBottomNav';
import { MobileMoreSheet } from '../mobile-more-sheet/MobileMoreSheet';
import { Sidebar } from '../sidebar/Sidebar';
import styles from './AppShell.module.css';

interface AppShellProps {
  children: ReactNode;
  contextPanel?: ReactNode;
  freshnessLabel: string;
  headerDate: string;
  path: string;
  setPath: (path: string) => void;
}

export function AppShell({
  children,
  contextPanel,
  freshnessLabel,
  headerDate,
  path,
  setPath,
}: AppShellProps) {
  const { t } = useTranslation();
  const [activeAction, setActiveAction] = useState<FoundationAction | null>(null);
  const [isCollapsed, setIsCollapsed] = useState(false);
  const [isMoreOpen, setIsMoreOpen] = useState(false);
  const actionTrigger = useRef<HTMLButtonElement>(null);
  const moreButton = useRef<HTMLButtonElement>(null);
  const title = t(getRouteTitleKey(path));
  const { handleNavigate, navigate } = useClientNavigation(path, setPath);

  function closeMoreSheet() {
    setIsMoreOpen(false);
    window.requestAnimationFrame(() => moreButton.current?.focus());
  }

  function openAction(action: FoundationAction, trigger: HTMLButtonElement) {
    actionTrigger.current = trigger;
    setActiveAction(action);
  }

  function closeAction() {
    setActiveAction(null);
    window.requestAnimationFrame(() => actionTrigger.current?.focus());
  }

  return (
    <div className={`${styles.shell}${isCollapsed ? ` ${styles.collapsed}` : ''}`} data-app-shell>
      <a className={styles.skipLink} href="#main-content">
        {t('accessibility.skipToContent')}
      </a>

      <Sidebar
        isCollapsed={isCollapsed}
        onNavigate={handleNavigate}
        onToggle={() => setIsCollapsed((value) => !value)}
        path={path}
      />

      <div className={styles.workspace}>
        <Header
          freshnessLabel={freshnessLabel}
          headerDate={headerDate}
          onAction={openAction}
          title={title}
        />

        <div className={`${styles.body}${contextPanel ? ` ${styles.bodyWithPanel}` : ''}`}>
          <main id="main-content" tabIndex={-1}>
            {children}
          </main>
          {contextPanel}
        </div>
      </div>

      <MobileBottomNav
        isMoreOpen={isMoreOpen}
        moreButton={moreButton}
        onAction={openAction}
        onNavigate={handleNavigate}
        onOpenMore={() => setIsMoreOpen(true)}
        path={path}
      />

      {isMoreOpen ? (
        <MobileMoreSheet close={closeMoreSheet} navigate={navigate} path={path} />
      ) : null}
      {activeAction ? <FoundationActionSheet action={activeAction} close={closeAction} /> : null}
    </div>
  );
}
