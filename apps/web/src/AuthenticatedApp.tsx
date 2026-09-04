import { getFoundationStatus } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AppShell } from '@/components/layout/app-shell/AppShell';
import { AccountsPage } from '@/features/accounts/AccountsPage';
import { LogoutButton } from '@/features/auth/logout-button/LogoutButton';
import { DashboardContextPanel } from '@/features/dashboard/context-panel/DashboardContextPanel';
import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { dashboardDemoData } from '@/features/dashboard/dashboardDemoData';
import { formatDemoDay } from '@/features/dashboard/formatDemoDate';
import { ProductCatalogPage } from '@/features/catalog/ProductCatalogPage';
import { ProductModelsPage } from '@/features/product-models/ProductModelsPage';
import { CategoryPage } from '@/features/categories/CategoryPage';
import { FoundationErrorState } from '@/features/foundation/FoundationErrorState';
import { FoundationLoadingState } from '@/features/foundation/FoundationLoadingState';
import { PlaceholderPage } from '@/features/not-found/PlaceholderPage';
import { getRouteTitleKey } from '@/lib/navigation';

/**
 * The application once a session exists: shell, routing and the foundation
 * probe. Rendered by AuthGate only for an authenticated request.
 */
export function AuthenticatedApp() {
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
  if (path === '/accounts') {
    content = <AccountsPage />;
  } else if (path === '/categories') {
    content = <CategoryPage />;
  } else if (path === '/catalog') {
    content = <ProductCatalogPage />;
  } else if (path === '/product-models') {
    content = <ProductModelsPage />;
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

  return (
    <AppShell
      accountSlot={<LogoutButton />}
      contextPanel={path === '/' && status.isSuccess ? <DashboardContextPanel /> : undefined}
      freshnessLabel={
        path === '/'
          ? t('header.staleFreshness', { count: dashboardDemoData.freshnessDays })
          : undefined
      }
      headerDate={
        path === '/' ? formatDemoDay(dashboardDemoData.asOfDate, i18n.language) : undefined
      }
      path={path}
      setPath={setPath}
      showGlobalActions={
        path !== '/accounts' &&
        path !== '/categories' &&
        path !== '/catalog' &&
        path !== '/product-models'
      }
    >
      {content}
    </AppShell>
  );
}
