import { getFoundationStatus } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AppShell } from './components/layout/app-shell/AppShell';
import { DashboardContextPanel } from './features/dashboard/context-panel/DashboardContextPanel';
import { DashboardPage } from './features/dashboard/DashboardPage';
import { dashboardDemoData } from './features/dashboard/dashboardDemoData';
import { formatDemoDay } from './features/dashboard/formatDemoDate';
import { FoundationErrorState } from './features/foundation/FoundationErrorState';
import { FoundationLoadingState } from './features/foundation/FoundationLoadingState';
import { PlaceholderPage } from './features/not-found/PlaceholderPage';
import { getRouteTitleKey } from './lib/navigation';

function App() {
  const { i18n, t } = useTranslation();
  const [path, setPath] = useState(() => window.location.pathname);
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
  if (path !== '/') {
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

  return (
    <AppShell
      contextPanel={path === '/' && status.isSuccess ? <DashboardContextPanel /> : undefined}
      freshnessLabel={t('header.staleFreshness', { count: dashboardDemoData.freshnessDays })}
      headerDate={formatDemoDay(dashboardDemoData.asOfDate, i18n.language)}
      path={path}
      setPath={setPath}
    >
      {content}
    </AppShell>
  );
}

export default App;
