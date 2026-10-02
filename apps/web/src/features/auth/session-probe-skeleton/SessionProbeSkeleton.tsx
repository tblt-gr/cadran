import { AppShell } from '@/components/layout/app-shell/AppShell';
import { FrozenQueries } from '@/components/layout/frozen-queries/FrozenQueries';
import { buildAuthenticatedRoutes, matchAuthenticatedRoute } from '@/AuthenticatedApp.routes';
import { DashboardContextPanel } from '@/features/dashboard/context-panel/DashboardContextPanel';
import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { PlaceholderPage } from '@/features/not-found/PlaceholderPage';

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
      <AppShell
        contextPanel={isDashboard ? <DashboardContextPanel /> : undefined}
        path={path}
        setPath={noop}
        showGlobalActions={matched ? (matched.route.globalActions ?? true) : true}
      >
        {content}
      </AppShell>
    </FrozenQueries>
  );
}
