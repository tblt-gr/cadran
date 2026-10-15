import { getFoundationStatus } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useCallback, useEffect, useMemo, useState, useTransition } from 'react';
import { AppShell } from '@/components/layout/app-shell/AppShell';
import { FrozenQueries } from '@/components/layout/frozen-queries/FrozenQueries';
import { LogoutButton } from '@/features/auth/logout-button/LogoutButton';
import { RouteErrorBoundary } from '@/components/layout/route-error-boundary/RouteErrorBoundary';
import { RouteSuspense } from '@/components/layout/route-suspense/RouteSuspense';
import { useDashboardHeader } from '@/features/dashboard/net-worth/useDashboardHeader';
import { FoundationErrorState } from '@/features/foundation/FoundationErrorState';
import { RouteLoadErrorState } from '@/features/foundation/RouteLoadErrorState';
import { PlaceholderPage } from '@/features/not-found/PlaceholderPage';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { getRouteTitleKey } from '@/lib/navigation';
import {
  buildAuthenticatedRoutes,
  DashboardPage,
  matchAuthenticatedRoute,
  preloadRoute,
} from './AuthenticatedApp.routes';

/**
 * The application once a session exists: shell, routing and the foundation
 * probe. Rendered by AuthGate only for an authenticated request.
 */
export function AuthenticatedApp() {
  const [path, setPath] = useState(() => window.location.pathname);
  const [isNavigating, startTransition] = useTransition();
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
  const runningVersion = status.isError ? null : status.data?.version;
  const routes = useMemo(
    () => buildAuthenticatedRoutes(workspaceTimeZone, runningVersion),
    [workspaceTimeZone, runningVersion],
  );
  const matched = matchAuthenticatedRoute(routes, path);

  // A path change is a transition: React keeps the page on screen until the
  // next page's chunk is ready, so in-app navigation never flashes a fallback.
  const navigateTo = useCallback(
    (nextPath: string) => {
      startTransition(() => setPath(nextPath));
    },
    [startTransition],
  );

  useEffect(() => {
    function updatePath() {
      navigateTo(window.location.pathname);
    }

    window.addEventListener('popstate', updatePath);
    // A redirect rendered on the first paint dispatches popstate from its own effect, which runs
    // before this one registers the listener: catch up once, a no-op when the path is unchanged.
    updatePath();
    return () => window.removeEventListener('popstate', updatePath);
  }, [navigateTo]);

  let content;
  if (matched) {
    content = matched.route.render(matched.match);
  } else if (path !== '/') {
    content = <PlaceholderPage />;
  } else if (status.isPending) {
    // The probe gate has a final layout to reproduce: the dashboard in its own
    // loading state, drawn without fetching until the API answers.
    content = (
      <FrozenQueries>
        <DashboardPage />
      </FrozenQueries>
    );
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
  return (
    <AppShell
      accountSlot={path === '/parameters/profile' ? <LogoutButton /> : undefined}
      freshnessLabel={path === '/' ? dashboardHeader.freshnessLabel : undefined}
      headerDate={path === '/' ? dashboardHeader.headerDate : undefined}
      isNavigating={isNavigating}
      onFreshnessClick={path === '/' ? dashboardHeader.onFreshnessClick : undefined}
      onNavigationIntent={preloadRoute}
      path={path}
      setPath={navigateTo}
    >
      <RouteErrorBoundary
        fallback={<RouteLoadErrorState titleKey={getRouteTitleKey(path)} />}
        resetKey={path}
      >
        <RouteSuspense titleKey={getRouteTitleKey(path)}>{content}</RouteSuspense>
      </RouteErrorBoundary>
    </AppShell>
  );
}
