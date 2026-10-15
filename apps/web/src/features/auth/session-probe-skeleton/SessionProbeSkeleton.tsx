import { AppShell } from '@/components/layout/app-shell/AppShell';
import { FrozenQueries } from '@/components/layout/frozen-queries/FrozenQueries';
import {
  buildAuthenticatedRoutes,
  DashboardPage,
  matchAuthenticatedRoute,
} from '@/AuthenticatedApp.routes';
import { RouteErrorBoundary } from '@/components/layout/route-error-boundary/RouteErrorBoundary';
import { RouteSuspense } from '@/components/layout/route-suspense/RouteSuspense';
import { RouteLoadErrorState } from '@/features/foundation/RouteLoadErrorState';
import { PlaceholderPage } from '@/features/not-found/PlaceholderPage';
import { getRouteTitleKey } from '@/lib/navigation';
import { DashboardPageFallback } from '@/features/dashboard/DashboardPageFallback';

const noop = () => undefined;

/**
 * Shown while the session probe is in flight: the shell and the screen of the
 * current URL in their loading state, so a refresh does not flash a blank or
 * generic screen before the real page mounts. Queries are frozen, so nothing is
 * requested before authentication is known; navigation stays inert until then.
 */
export function SessionProbeSkeleton() {
  const path = window.location.pathname;
  const timeZone = Intl.DateTimeFormat().resolvedOptions().timeZone;
  const matched = matchAuthenticatedRoute(buildAuthenticatedRoutes(timeZone), path);
  const isDashboard = path === '/';

  let content = null;
  if (matched) {
    content = matched.route.redirect ? null : matched.route.render(matched.match);
  } else if (isDashboard) {
    content = <DashboardPage />;
  } else {
    content = <PlaceholderPage />;
  }

  return (
    <FrozenQueries>
      <AppShell path={path} setPath={noop}>
        <RouteErrorBoundary
          fallback={<RouteLoadErrorState titleKey={getRouteTitleKey(path)} />}
          resetKey={path}
        >
          <RouteSuspense
            fallback={path === '/' ? <DashboardPageFallback /> : undefined}
            titleKey={getRouteTitleKey(path)}
          >
            {content}
          </RouteSuspense>
        </RouteErrorBoundary>
      </AppShell>
    </FrozenQueries>
  );
}
