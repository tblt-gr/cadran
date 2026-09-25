import type { ReactNode } from 'react';
import { RouteRedirect } from '@/components/layout/route-redirect/RouteRedirect';
import { AccountsPage } from '@/features/accounts/AccountsPage';
import { AccountDetailPage } from '@/features/accounts/account-detail/AccountDetailPage';
import { AccountGroupsPage } from '@/features/accounts/groups/AccountGroupsPage';
import { ProductCatalogPage } from '@/features/catalog/ProductCatalogPage';
import { ProductModelsPage } from '@/features/product-models/ProductModelsPage';
import { CategoryPage } from '@/features/categories/CategoryPage';
import { CategorizationRulesPage } from '@/features/categories/rules/CategorizationRulesPage';
import { TransactionsPage } from '@/features/transactions/TransactionsPage';
import { RecurrencesPage } from '@/features/recurrences/RecurrencesPage';
import { PlaceholderPage } from '@/features/not-found/PlaceholderPage';
import { ProfileSettingsPage } from '@/features/settings/ProfileSettingsPage';
import { BudgetPage } from '@/features/budget/BudgetPage';
import { BudgetRouteRedirect } from '@/features/budget/monthly-budget/BudgetRouteRedirect';
import { MonthlyBudgetPage } from '@/features/budget/monthly-budget/MonthlyBudgetPage';
import { ReportsPage } from '@/features/reports/ReportsPage';
import { workspaceToday } from '@/lib/workspaceTime';

const BUDGET_PLAN_UUID_PATTERN =
  '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

export interface RouteDefinition {
  /** Matched with `.exec(path)` against the full pathname; capture groups become path params. */
  pattern: RegExp;
  /** Whether the shell's global create/search actions apply to this route. Defaults to true. */
  globalActions?: boolean;
  render: (match: RegExpExecArray) => ReactNode;
}

/**
 * Every route this shell serves once authenticated, checked in order — first
 * match wins, exactly like the if/else chain it replaces. The dashboard ('/')
 * and the 404 placeholder are not listed here: both depend on the foundation
 * status query and stay in AuthenticatedApp, which renders them only when no
 * entry below matches.
 *
 * `workspaceTimeZone` is a parameter rather than a closed-over value because
 * the one route that needs it (the bare `/budget` redirect) must resolve
 * "today" in the workspace's own zone, not a build-time constant.
 */
export function buildAuthenticatedRoutes(workspaceTimeZone: string): RouteDefinition[] {
  return [
    { pattern: /^\/accounts$/, globalActions: false, render: () => <AccountsPage /> },
    {
      pattern: /^\/accounts\/groups$/,
      globalActions: false,
      render: () => <AccountGroupsPage />,
    },
    {
      pattern: /^\/accounts\/([^/]+)$/,
      globalActions: false,
      render: (match) => <AccountDetailPage accountId={match[1]!} />,
    },
    {
      pattern: /^\/transactions\/categories$/,
      globalActions: false,
      render: () => <CategoryPage />,
    },
    {
      pattern: /^\/transactions\/categories\/rules$/,
      globalActions: false,
      render: () => <CategorizationRulesPage />,
    },
    {
      pattern: /^\/categories$/,
      globalActions: false,
      render: () => <RouteRedirect to="/transactions/categories" />,
    },
    {
      pattern: /^\/categories\/rules$/,
      globalActions: false,
      render: () => <RouteRedirect to="/transactions/categories/rules" />,
    },
    { pattern: /^\/catalog$/, globalActions: false, render: () => <ProductCatalogPage /> },
    {
      pattern: /^\/product-models$/,
      globalActions: false,
      render: () => <ProductModelsPage />,
    },
    { pattern: /^\/transactions$/, globalActions: false, render: () => <TransactionsPage /> },
    {
      pattern: /^\/transactions\/recurrences$/,
      globalActions: false,
      render: () => <RecurrencesPage />,
    },
    {
      pattern: /^\/budget$/,
      globalActions: false,
      render: () => (
        <BudgetRouteRedirect
          href={`/budget/${workspaceToday(new Date(), workspaceTimeZone).slice(0, 7)}`}
        />
      ),
    },
    { pattern: /^\/budget\/plans$/, globalActions: false, render: () => <BudgetPage /> },
    {
      pattern: /^\/budget\/plans\/([^/]+)$/,
      globalActions: false,
      render: (match) => <BudgetPage planId={match[1]} />,
    },
    { pattern: /^\/reports$/, globalActions: false, render: () => <ReportsPage /> },
    {
      pattern: new RegExp(`^/budget/(${BUDGET_PLAN_UUID_PATTERN})$`, 'i'),
      globalActions: false,
      render: (match) => <BudgetRouteRedirect href={`/budget/plans/${match[1]}`} />,
    },
    {
      pattern: /^\/budget\/([^/]+)$/,
      globalActions: false,
      render: (match) => <MonthlyBudgetPage periodKey={match[1]!} />,
    },
    {
      pattern: /^\/settings\/profile$/,
      globalActions: false,
      render: () => <ProfileSettingsPage />,
    },
    // Catch-all, checked last: an unmatched path still under /budget (e.g. an
    // extra path segment) is a 404 like any other, but the budget workbook's
    // own header already covers the shell's global create/search actions, so
    // they stay hidden here exactly as they do for every matched budget route.
    {
      pattern: /^\/budget\//,
      globalActions: false,
      render: () => <PlaceholderPage />,
    },
  ];
}

/** First route whose pattern matches `path`, or null for the dashboard / 404 fallback. */
export function matchAuthenticatedRoute(
  routes: RouteDefinition[],
  path: string,
): { route: RouteDefinition; match: RegExpExecArray } | null {
  for (const route of routes) {
    const match = route.pattern.exec(path);
    if (match) return { route, match };
  }
  return null;
}
