import { useRef, useState, type MouseEvent, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Icon } from '../design-system/Icon';
import { FoundationActionSheet, type FoundationAction } from './FoundationActionSheet';
import { MobileMoreSheet } from './MobileMoreSheet';
import { getRouteTitleKey, navigationItems, type NavigationItem } from './navigation';

interface AppShellProps {
  children: ReactNode;
  contextPanel?: ReactNode;
  freshnessLabel: string;
  headerDate: string;
  path: string;
  setPath: (path: string) => void;
}

interface NavLinkProps {
  item: NavigationItem;
  onNavigate: (event: MouseEvent<HTMLAnchorElement>, href: string) => void;
  path: string;
}

function NavLink({ item, onNavigate, path }: NavLinkProps) {
  const { t } = useTranslation();
  const isCurrent = item.match(path);
  const label = t(item.labelKey);

  return (
    <a
      aria-current={isCurrent ? 'page' : undefined}
      className="nav-link"
      href={item.href}
      onClick={(event) => onNavigate(event, item.href)}
      title={label}
    >
      <Icon name={item.icon} />
      <span>{label}</span>
    </a>
  );
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
  const isMoreCurrent = navigationItems.some((item) => !item.mobile && item.match(path));

  function navigate(nextPath: string) {
    if (nextPath === path) return;
    window.history.pushState({}, '', nextPath);
    setPath(nextPath);
  }

  function handleNavigate(event: MouseEvent<HTMLAnchorElement>, href: string) {
    if (
      event.button !== 0 ||
      event.altKey ||
      event.ctrlKey ||
      event.metaKey ||
      event.shiftKey ||
      event.currentTarget.target === '_blank'
    ) {
      return;
    }

    event.preventDefault();
    navigate(href);
  }

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
    <div className={`app-shell${isCollapsed ? ' app-shell--collapsed' : ''}`}>
      <a className="skip-link" href="#main-content">
        {t('accessibility.skipToContent')}
      </a>

      <aside className="sidebar">
        <div className="brand" aria-label={t('app.name')}>
          <svg aria-hidden="true" className="brand__mark" viewBox="0 0 40 40">
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

        <nav aria-label={t('navigation.mainLabel')} className="sidebar__nav">
          {navigationItems.map((item) => (
            <NavLink item={item} key={item.href} onNavigate={handleNavigate} path={path} />
          ))}
        </nav>

        <button
          aria-label={isCollapsed ? t('navigation.expand') : t('navigation.collapse')}
          className="sidebar__collapse"
          onClick={() => setIsCollapsed((value) => !value)}
          type="button"
        >
          <Icon name={isCollapsed ? 'chevron-right' : 'chevron-left'} />
          <span>{isCollapsed ? t('actions.expand') : t('actions.collapse')}</span>
        </button>
      </aside>

      <div className="workspace">
        <header className="topbar">
          <div>
            <p className="topbar__date">{headerDate}</p>
            <h1>{title}</h1>
          </div>
          <div className="topbar__actions">
            <span className="freshness">
              <span className="freshness__dot" />
              {freshnessLabel}
            </span>
            <button
              aria-label={t('actions.search')}
              className="icon-button topbar__search"
              onClick={(event) => openAction('search', event.currentTarget)}
              type="button"
            >
              <Icon name="search" />
            </button>
            <button
              className="primary-action"
              onClick={(event) => openAction('add', event.currentTarget)}
              type="button"
            >
              <Icon name="add" size={18} />
              {t('actions.add')}
            </button>
          </div>
        </header>

        <div className={`workspace__body${contextPanel ? ' workspace__body--with-panel' : ''}`}>
          <main id="main-content" tabIndex={-1}>
            {children}
          </main>
          {contextPanel}
        </div>
      </div>

      <nav aria-label={t('navigation.mobileLabel')} className="bottom-nav">
        {navigationItems
          .filter((item) => item.mobile)
          .slice(0, 2)
          .map((item) => (
            <NavLink item={item} key={item.href} onNavigate={handleNavigate} path={path} />
          ))}
        <button
          aria-label={t('actions.add')}
          className="bottom-nav__add"
          onClick={(event) => openAction('add', event.currentTarget)}
          type="button"
        >
          <Icon name="add" size={24} />
          <span>{t('actions.add')}</span>
        </button>
        {navigationItems
          .filter((item) => item.mobile)
          .slice(2)
          .map((item) => (
            <NavLink item={item} key={item.href} onNavigate={handleNavigate} path={path} />
          ))}
        <button
          aria-current={isMoreCurrent ? 'page' : undefined}
          aria-expanded={isMoreOpen}
          aria-haspopup="dialog"
          className="nav-link"
          onClick={() => setIsMoreOpen(true)}
          ref={moreButton}
          type="button"
        >
          <Icon name="more" />
          <span>{t('actions.more')}</span>
        </button>
      </nav>

      {isMoreOpen ? (
        <MobileMoreSheet close={closeMoreSheet} navigate={navigate} path={path} />
      ) : null}
      {activeAction ? <FoundationActionSheet action={activeAction} close={closeAction} /> : null}
    </div>
  );
}
