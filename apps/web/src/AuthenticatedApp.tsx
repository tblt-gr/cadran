import { getFoundationStatus } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { AppShell } from '@/components/layout/app-shell/AppShell';
import { AccountsPage } from '@/features/accounts/AccountsPage';
import { LogoutButton } from '@/features/auth/logout-button/LogoutButton';
import { DashboardContextPanel } from '@/features/dashboard/context-panel/DashboardContextPanel';
import { DashboardPage } from '@/features/dashboard/DashboardPage';
import { useDashboardHeader } from '@/features/dashboard/net-worth/useDashboardHeader';
import { ProductCatalogPage } from '@/features/catalog/ProductCatalogPage';
import { ProductModelsPage } from '@/features/product-models/ProductModelsPage';
import { AccountGroupsPage } from '@/features/account-groups/AccountGroupsPage';
import { CategoryPage } from '@/features/categories/CategoryPage';
import { CategorizationRulesPage } from '@/features/categories/rules/CategorizationRulesPage';
import { TransactionsPage } from '@/features/transactions/TransactionsPage';
import { RecurrencesPage } from '@/features/recurrences/RecurrencesPage';
import { FoundationErrorState } from '@/features/foundation/FoundationErrorState';
import { FoundationLoadingState } from '@/features/foundation/FoundationLoadingState';
import { PlaceholderPage } from '@/features/not-found/PlaceholderPage';
import { ProfileSettingsPage } from '@/features/settings/ProfileSettingsPage';
import { getRouteTitleKey } from '@/lib/navigation';

/**
 * The application once a session exists: shell, routing and the foundation
 * probe. Rendered by AuthGate only for an authenticated request.
 */
export function AuthenticatedApp() {
  const { t } = useTranslation();
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

  const dashboardHeader = useDashboardHeader(path === '/');

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
  } else if (path === '/account-groups') {
    content = <AccountGroupsPage />;
  } else if (path === '/categories') {
    content = <CategoryPage />;
  } else if (path === '/categories/rules') {
    content = <CategorizationRulesPage />;
  } else if (path === '/catalog') {
    content = <ProductCatalogPage />;
  } else if (path === '/product-models') {
    content = <ProductModelsPage />;
  } else if (path === '/transactions') {
    content = <TransactionsPage />;
  } else if (path === '/recurrences') {
    content = <RecurrencesPage />;
  } else if (path === '/settings/profile') {
    content = <ProfileSettingsPage />;
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
      accountSlot={path === '/settings/profile' ? <LogoutButton /> : undefined}
      contextPanel={path === '/' && status.isSuccess ? <DashboardContextPanel /> : undefined}
      freshnessLabel={path === '/' ? dashboardHeader.freshnessLabel : undefined}
      headerDate={path === '/' ? dashboardHeader.headerDate : undefined}
      path={path}
      setPath={setPath}
      showGlobalActions={
        path !== '/accounts' &&
        path !== '/account-groups' &&
        path !== '/categories' &&
        path !== '/categories/rules' &&
        path !== '/catalog' &&
        path !== '/product-models' &&
        path !== '/transactions' &&
        path !== '/recurrences' &&
        path !== '/settings/profile'
      }
    >
      {content}
    </AppShell>
  );
}
