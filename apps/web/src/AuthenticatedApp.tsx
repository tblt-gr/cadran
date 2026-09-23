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
import { AccountGroupsPage } from '@/features/accounts/groups/AccountGroupsPage';
import { CategoryPage } from '@/features/categories/CategoryPage';
import { CategorizationRulesPage } from '@/features/categories/rules/CategorizationRulesPage';
import { TransactionsPage } from '@/features/transactions/TransactionsPage';
import { RecurrencesPage } from '@/features/recurrences/RecurrencesPage';
import { FoundationErrorState } from '@/features/foundation/FoundationErrorState';
import { FoundationLoadingState } from '@/features/foundation/FoundationLoadingState';
import { PlaceholderPage } from '@/features/not-found/PlaceholderPage';
import { ProfileSettingsPage } from '@/features/settings/ProfileSettingsPage';
import { BudgetPage } from '@/features/budget/BudgetPage';
import { BudgetRouteRedirect } from '@/features/budget/monthly-budget/BudgetRouteRedirect';
import { workspaceToday } from '@/features/budget/monthly-budget/budgetPeriod';
import { MonthlyBudgetPage } from '@/features/budget/monthly-budget/MonthlyBudgetPage';
import { ReportsPage } from '@/features/reports/ReportsPage';
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
  } else if (path === '/accounts/groups') {
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
  } else if (path === '/transactions/recurrences') {
    content = <RecurrencesPage />;
  } else if (path === '/budget') {
    content = <BudgetRouteRedirect href={`/budget/${workspaceToday().slice(0, 7)}`} />;
  } else if (path === '/budget/plans') {
    content = <BudgetPage />;
  } else if (/^\/budget\/plans\/[^/]+$/.test(path)) {
    content = <BudgetPage planId={path.split('/')[3]} />;
  } else if (path === '/reports') {
    content = <ReportsPage />;
  } else if (
    /^\/budget\/[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(
      path,
    )
  ) {
    content = <BudgetRouteRedirect href={`/budget/plans/${path.split('/')[2]}`} />;
  } else if (/^\/budget\/[^/]+$/.test(path)) {
    content = <MonthlyBudgetPage periodKey={path.split('/')[2]!} />;
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
        path !== '/accounts/groups' &&
        path !== '/categories' &&
        path !== '/categories/rules' &&
        path !== '/catalog' &&
        path !== '/product-models' &&
        path !== '/transactions' &&
        path !== '/transactions/recurrences' &&
        !path.startsWith('/budget') &&
        path !== '/reports' &&
        path !== '/settings/profile'
      }
    >
      {content}
    </AppShell>
  );
}
