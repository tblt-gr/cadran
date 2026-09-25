import { getFoundationStatus } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AppShell } from '@/components/layout/app-shell/AppShell';
import { LogoutButton } from '@/features/auth/logout-button/LogoutButton';
import { DashboardContextPanel } from '@/features/dashboard/context-panel/DashboardContextPanel';
import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { useDashboardHeader } from '@/features/dashboard/net-worth/useDashboardHeader';
import { FoundationErrorState } from '@/features/foundation/FoundationErrorState';
import { FoundationLoadingState } from '@/features/foundation/FoundationLoadingState';
import { PlaceholderPage } from '@/features/not-found/PlaceholderPage';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { getRouteTitleKey } from '@/lib/navigation';
import { buildAuthenticatedRoutes, matchAuthenticatedRoute } from './AuthenticatedApp.routes';

/**
 * The application once a session exists: shell, routing and the foundation
 * probe. Rendered by AuthGate only for an authenticated request.
 */
export function AuthenticatedApp() {
  const { t } = useTranslation();
  const [path, setPath] = useState(() => window.location.pathname);
  // AuthGate only renders this tree once the session query already resolved
  // to an authenticated, workspace-bound user, so this read is a cache hit:
  // no extra request and no loading state to handle here.
  const workspaceTimeZone = useWorkspaceTimeZone();
  const status = useQuery({
    queryKey: ['foundation-status'],
    queryFn: async ({ signal }) => {
      const response = await getFoundationStatus({
        baseUrl: window.location.origin,
        signal,
        throwOnError: true,
      });

      return response.data;
    },
    retry: false,
  });

  const dashboardHeader = useDashboardHeader(path === '/');
  const routes = useMemo(() => buildAuthenticatedRoutes(workspaceTimeZone), [workspaceTimeZone]);
  const matched = matchAuthenticatedRoute(routes, path);

  useEffect(() => {
    function updatePath() {
      setPath(window.location.pathname);
    }

    window.addEventListener('popstate', updatePath);
    return () => window.removeEventListener('popstate', updatePath);
  }, []);

  useEffect(() => {
    const routeTitle = t(getRouteTitleKey(path));
    document.title = path === '/' ? t('app.name') : `${routeTitle} · ${t('app.name')}`;
  }, [path, t]);

  let content;
  if (matched) {
    content = matched.route.render(matched.match);
  } else if (path !== '/') {
    content = <PlaceholderPage />;
  } else if (status.isPending) {
    content = <FoundationLoadingState />;
  } else if (status.isError) {
    content = (
      <FoundationErrorState
        retry={() => {
          void status.refetch();
        }}
      />
    );
  } else {
    content = <DashboardPage apiVersion={status.data.apiVersion} />;
  }
  const showGlobalActions = matched ? (matched.route.globalActions ?? true) : true;

  return (
    <AppShell
      accountSlot={path === '/settings/profile' ? <LogoutButton /> : undefined}
      contextPanel={path === '/' && status.isSuccess ? <DashboardContextPanel /> : undefined}
      freshnessLabel={path === '/' ? dashboardHeader.freshnessLabel : undefined}
      headerDate={path === '/' ? dashboardHeader.headerDate : undefined}
      path={path}
      setPath={setPath}
      showGlobalActions={showGlobalActions}
    >
      {content}
    </AppShell>
  );
}
