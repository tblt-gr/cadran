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
import { MetricPolicyPage } from '@/features/metric-policy/MetricPolicyPage';
import { ProfileSettingsPage } from '@/features/settings/ProfileSettingsPage';
import { BudgetPage } from '@/features/budget/BudgetPage';
import { BudgetRouteRedirect } from '@/features/budget/monthly-budget/BudgetRouteRedirect';
import { MonthlyBudgetPage } from '@/features/budget/monthly-budget/MonthlyBudgetPage';
import { AnnualReportPage } from '@/features/reports/annual/AnnualReportPage';
import { MultiYearReportPage } from '@/features/reports/multi-year/MultiYearReportPage';
import { GoalsPage } from '@/features/goals/GoalsPage';
import { InstrumentsPage } from '@/features/portfolio/instruments/InstrumentsPage';
import { PortfoliosPage } from '@/features/portfolio/portfolios/PortfoliosPage';
import { PortfolioAccountPage } from '@/features/portfolio/portfolio-account/PortfolioAccountPage';
import { ReportsPage } from '@/features/reports/ReportsPage';
import { workspaceToday } from '@/lib/workspaceTime';

const BUDGET_PLAN_UUID_PATTERN =
  '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

export interface RouteDefinition {
  /** Matched with `.exec(path)` against the full pathname; capture groups become path params. */
  pattern: RegExp;
  /** Pure redirect: nothing to preview while the session is still being checked. */
  redirect?: boolean;
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
 * `runningVersion` comes from the foundation status (`undefined` while it
 * loads, `null` when it failed) and is only shown by the profile settings.
 */
export function buildAuthenticatedRoutes(
  workspaceTimeZone: string,
  runningVersion?: string | null,
): RouteDefinition[] {
  return [
    { pattern: /^\/portfolios$/, render: () => <PortfoliosPage /> },
    {
      pattern: /^\/portfolios\/instruments$/,
      render: () => <InstrumentsPage />,
    },
    {
      pattern: /^\/portfolios\/(?!instruments$)([^/]+)$/,
      render: (match) => <PortfolioAccountPage accountId={match[1]!} />,
    },
    { pattern: /^\/accounts$/, render: () => <AccountsPage /> },
    {
      pattern: /^\/accounts\/groups$/,
      render: () => <AccountGroupsPage />,
    },
    {
      pattern: /^\/accounts\/([^/]+)$/,
      render: (match) => <AccountDetailPage accountId={match[1]!} />,
    },
    {
      pattern: /^\/transactions\/categories$/,
      render: () => <CategoryPage />,
    },
    {
      pattern: /^\/transactions\/categories\/rules$/,
      render: () => <CategorizationRulesPage />,
    },
    {
      pattern: /^\/categories$/,
      redirect: true,
      render: () => <RouteRedirect to="/transactions/categories" />,
    },
    {
      pattern: /^\/categories\/rules$/,
      redirect: true,
      render: () => <RouteRedirect to="/transactions/categories/rules" />,
    },
    { pattern: /^\/parameters\/catalog$/, render: () => <ProductCatalogPage /> },
    { pattern: /^\/parameters\/product-models$/, render: () => <ProductModelsPage /> },
    {
      pattern: /^\/catalog$/,
      redirect: true,
      render: () => <RouteRedirect to="/parameters/catalog" />,
    },
    {
      pattern: /^\/product-models$/,
      redirect: true,
      render: () => <RouteRedirect to="/parameters/product-models" />,
    },
    {
      pattern: /^\/settings\/(profile|metric-policy)$/,
      redirect: true,
      render: (match) => <RouteRedirect to={`/parameters/${match[1]}`} />,
    },
    { pattern: /^\/transactions$/, render: () => <TransactionsPage /> },
    {
      pattern: /^\/transactions\/recurrences$/,
      render: () => <RecurrencesPage />,
    },
    {
      pattern: /^\/budget$/,
      redirect: true,
      render: () => (
        <BudgetRouteRedirect
          href={`/budget/${workspaceToday(new Date(), workspaceTimeZone).slice(0, 7)}`}
        />
      ),
    },
    { pattern: /^\/budget\/plans$/, render: () => <BudgetPage /> },
    {
      pattern: /^\/budget\/plans\/([^/]+)$/,
      render: (match) => <BudgetPage planId={match[1]} />,
    },
    { pattern: /^\/reports$/, render: () => <ReportsPage /> },
    { pattern: /^\/goals$/, render: () => <GoalsPage /> },
    {
      pattern: /^\/reports\/annual\/([^/]+)$/,
      render: (match) => {
        const today = workspaceToday(new Date(), workspaceTimeZone);
        const currentYear = Number(today.slice(0, 4));
        const year = /^\d{4}$/.test(match[1]!) ? Number(match[1]) : Number.NaN;
        // An unknown year (not a year, before 1900 or in the future) is not an error
        // worth showing: land on the current year instead of the 422 state.
        if (!(year >= 1900 && year <= currentYear)) {
          return (
            <RouteRedirect statusKey="reports.loading" to={`/reports/annual/${currentYear}`} />
          );
        }
        return <AnnualReportPage today={today} year={year} />;
      },
    },
    {
      pattern: /^\/reports\/all-years$/,
      render: () => <MultiYearReportPage today={workspaceToday(new Date(), workspaceTimeZone)} />,
    },
    {
      pattern: new RegExp(`^/budget/(${BUDGET_PLAN_UUID_PATTERN})$`, 'i'),
      redirect: true,
      render: (match) => <BudgetRouteRedirect href={`/budget/plans/${match[1]}`} />,
    },
    {
      pattern: /^\/budget\/([^/]+)$/,
      render: (match) => <MonthlyBudgetPage periodKey={match[1]!} />,
    },
    {
      pattern: /^\/parameters\/profile$/,
      render: () => <ProfileSettingsPage runningVersion={runningVersion} />,
    },
    {
      pattern: /^\/parameters\/metric-policy$/,
      render: () => <MetricPolicyPage />,
    },
    // Catch-all, checked last: an unmatched path still under /budget is a 404.
    {
      pattern: /^\/budget\//,
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
