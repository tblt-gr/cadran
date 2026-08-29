import { getFoundationStatus } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AppShell } from './app/AppShell';
import { getRouteTitleKey } from './app/navigation';
import { Icon } from './design-system/Icon';
import { DashboardContextPanel, DashboardPage } from './features/dashboard/DashboardPage';
import { dashboardDemoData } from './features/dashboard/dashboardDemoData';
import { formatDemoDay } from './features/dashboard/formatDemoDate';
import './App.css';

function LoadingState() {
  const { t } = useTranslation();

  return (
    <section className="card shell-state" aria-labelledby="loading-title">
      <div className="shell-state__mark shell-state__mark--loading" aria-hidden="true" />
      <div>
        <h2 id="loading-title">{t('states.loading.title')}</h2>
        <p role="status">{t('foundation.loading')}</p>
      </div>
      <div className="skeleton-stack" aria-hidden="true">
        <span />
        <span />
        <span />
      </div>
    </section>
  );
}

function ErrorState({ retry }: { retry: () => void }) {
  const { t } = useTranslation();

  return (
    <section className="card shell-state" aria-labelledby="error-title">
      <div className="shell-state__mark shell-state__mark--error">
        <Icon name="alert" size={24} />
      </div>
      <div role="alert">
        <h2 id="error-title">{t('states.error.title')}</h2>
        <p>{t('states.error.description')}</p>
      </div>
      <button className="secondary-action" onClick={retry} type="button">
        {t('foundation.retry')}
      </button>
    </section>
  );
}

function PlaceholderPage() {
  const { t } = useTranslation();

  return (
    <section className="card placeholder-page" aria-labelledby="placeholder-title">
      <div className="placeholder-page__mark">
        <Icon name="menu" size={28} />
      </div>
      <h2 id="placeholder-title">{t('states.placeholder.title')}</h2>
      <p>{t('states.placeholder.description')}</p>
      <a className="secondary-action" href="/">
        {t('actions.backHome')}
      </a>
    </section>
  );
}

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
    content = <LoadingState />;
  } else if (status.isError) {
    content = (
      <ErrorState
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
