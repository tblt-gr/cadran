import type { ReactNode } from 'react';
import { RouteRedirect } from '@/components/layout/route-redirect/RouteRedirect';
import { BudgetRouteRedirect } from '@/features/budget/monthly-budget/BudgetRouteRedirect';
import { PlaceholderPage } from '@/features/not-found/PlaceholderPage';
import { lazyComponent } from '@/lib/lazyComponent';
import { workspaceToday } from '@/lib/workspaceTime';

/** Not a route entry: the root path depends on the foundation probe and is rendered by AuthenticatedApp. */
export const DashboardPage = lazyComponent(
  () => import('@/features/dashboard/DashboardPage'),
  'DashboardPage',
);
const AccountsPage = lazyComponent(
  () => import('@/features/accounts/AccountsPage'),
  'AccountsPage',
);
const AccountDetailPage = lazyComponent(
  () => import('@/features/accounts/account-detail/AccountDetailPage'),
  'AccountDetailPage',
);
const AccountGroupsPage = lazyComponent(
  () => import('@/features/accounts/groups/AccountGroupsPage'),
  'AccountGroupsPage',
);
const ProductCatalogPage = lazyComponent(
  () => import('@/features/catalog/ProductCatalogPage'),
  'ProductCatalogPage',
);
const ProductModelsPage = lazyComponent(
  () => import('@/features/product-models/ProductModelsPage'),
  'ProductModelsPage',
);
const CategoryPage = lazyComponent(
  () => import('@/features/categories/CategoryPage'),
  'CategoryPage',
);
const CategorizationRulesPage = lazyComponent(
  () => import('@/features/categories/rules/CategorizationRulesPage'),
  'CategorizationRulesPage',
);
const TransactionsPage = lazyComponent(
  () => import('@/features/transactions/TransactionsPage'),
  'TransactionsPage',
);
const RecurrencesPage = lazyComponent(
  () => import('@/features/recurrences/RecurrencesPage'),
  'RecurrencesPage',
);
const MetricPolicyPage = lazyComponent(
  () => import('@/features/metric-policy/MetricPolicyPage'),
  'MetricPolicyPage',
);
const ProfileSettingsPage = lazyComponent(
  () => import('@/features/settings/ProfileSettingsPage'),
  'ProfileSettingsPage',
);
const BudgetPage = lazyComponent(() => import('@/features/budget/BudgetPage'), 'BudgetPage');
const MonthlyBudgetPage = lazyComponent(
  () => import('@/features/budget/monthly-budget/MonthlyBudgetPage'),
  'MonthlyBudgetPage',
);
const AnnualReportPage = lazyComponent(
  () => import('@/features/reports/annual/AnnualReportPage'),
  'AnnualReportPage',
);
const MultiYearReportPage = lazyComponent(
  () => import('@/features/reports/multi-year/MultiYearReportPage'),
  'MultiYearReportPage',
);
const GoalsPage = lazyComponent(() => import('@/features/goals/GoalsPage'), 'GoalsPage');
const InstrumentsPage = lazyComponent(
  () => import('@/features/portfolio/instruments/InstrumentsPage'),
  'InstrumentsPage',
);
const PortfoliosPage = lazyComponent(
  () => import('@/features/portfolio/portfolios/PortfoliosPage'),
  'PortfoliosPage',
);
const PortfolioAccountPage = lazyComponent(
  () => import('@/features/portfolio/portfolio-account/PortfolioAccountPage'),
  'PortfolioAccountPage',
);
const ReportsPage = lazyComponent(() => import('@/features/reports/ReportsPage'), 'ReportsPage');

const BUDGET_PLAN_UUID_PATTERN =
  '[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

export interface RouteDefinition {
  /** Matched with `.exec(path)` against the full pathname; capture groups become path params. */
  pattern: RegExp;
  /** Pure redirect: nothing to preview while the session is still being checked. */
  redirect?: boolean;
  /** Requests the page chunk ahead of its render; a redirect points at its destination page. */
  preload?: () => Promise<unknown>;
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
    {
      pattern: /^\/portfolios$/,
      preload: PortfoliosPage.preload,
      render: () => <PortfoliosPage />,
    },
    {
      pattern: /^\/portfolios\/instruments$/,
      preload: InstrumentsPage.preload,
      render: () => <InstrumentsPage />,
    },
    {
      pattern: /^\/portfolios\/(?!instruments$)([^/]+)$/,
      preload: PortfolioAccountPage.preload,
      render: (match) => <PortfolioAccountPage accountId={match[1]!} />,
    },
    { pattern: /^\/accounts$/, preload: AccountsPage.preload, render: () => <AccountsPage /> },
    {
      pattern: /^\/accounts\/groups$/,
      preload: AccountGroupsPage.preload,
      render: () => <AccountGroupsPage />,
    },
    {
      pattern: /^\/accounts\/([^/]+)$/,
      preload: AccountDetailPage.preload,
      render: (match) => <AccountDetailPage accountId={match[1]!} />,
    },
    {
      pattern: /^\/transactions\/categories$/,
      preload: CategoryPage.preload,
      render: () => <CategoryPage />,
    },
    {
      pattern: /^\/transactions\/categories\/rules$/,
      preload: CategorizationRulesPage.preload,
      render: () => <CategorizationRulesPage />,
    },
    {
      pattern: /^\/categories$/,
      preload: CategoryPage.preload,
      redirect: true,
      render: () => <RouteRedirect to="/transactions/categories" />,
    },
    {
      pattern: /^\/categories\/rules$/,
      preload: CategorizationRulesPage.preload,
      redirect: true,
      render: () => <RouteRedirect to="/transactions/categories/rules" />,
    },
    {
      pattern: /^\/parameters\/catalog$/,
      preload: ProductCatalogPage.preload,
      render: () => <ProductCatalogPage />,
    },
    {
      pattern: /^\/parameters\/product-models$/,
      preload: ProductModelsPage.preload,
      render: () => <ProductModelsPage />,
    },
    {
      pattern: /^\/catalog$/,
      preload: ProductCatalogPage.preload,
      redirect: true,
      render: () => <RouteRedirect to="/parameters/catalog" />,
    },
    {
      pattern: /^\/product-models$/,
      preload: ProductModelsPage.preload,
      redirect: true,
      render: () => <RouteRedirect to="/parameters/product-models" />,
    },
    {
      pattern: /^\/settings\/(profile|metric-policy)$/,
      redirect: true,
      render: (match) => <RouteRedirect to={`/parameters/${match[1]}`} />,
    },
    {
      pattern: /^\/transactions$/,
      preload: TransactionsPage.preload,
      render: () => <TransactionsPage />,
    },
    {
      pattern: /^\/transactions\/recurrences$/,
      preload: RecurrencesPage.preload,
      render: () => <RecurrencesPage />,
    },
    {
      pattern: /^\/budget$/,
      preload: MonthlyBudgetPage.preload,
      redirect: true,
      render: () => (
        <BudgetRouteRedirect
          href={`/budget/${workspaceToday(new Date(), workspaceTimeZone).slice(0, 7)}`}
        />
      ),
    },
    { pattern: /^\/budget\/plans$/, preload: BudgetPage.preload, render: () => <BudgetPage /> },
    {
      pattern: /^\/budget\/plans\/([^/]+)$/,
      preload: BudgetPage.preload,
      render: (match) => <BudgetPage planId={match[1]} />,
    },
    { pattern: /^\/reports$/, preload: ReportsPage.preload, render: () => <ReportsPage /> },
    { pattern: /^\/goals$/, preload: GoalsPage.preload, render: () => <GoalsPage /> },
    {
      pattern: /^\/reports\/annual\/([^/]+)$/,
      preload: AnnualReportPage.preload,
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
      preload: MultiYearReportPage.preload,
      render: () => <MultiYearReportPage today={workspaceToday(new Date(), workspaceTimeZone)} />,
    },
    {
      pattern: new RegExp(`^/budget/(${BUDGET_PLAN_UUID_PATTERN})$`, 'i'),
      redirect: true,
      render: (match) => <BudgetRouteRedirect href={`/budget/plans/${match[1]}`} />,
    },
    {
      pattern: /^\/budget\/([^/]+)$/,
      preload: MonthlyBudgetPage.preload,
      render: (match) => <MonthlyBudgetPage periodKey={match[1]!} />,
    },
    {
      pattern: /^\/parameters\/profile$/,
      preload: ProfileSettingsPage.preload,
      render: () => <ProfileSettingsPage runningVersion={runningVersion} />,
    },
    {
      pattern: /^\/parameters\/metric-policy$/,
      preload: MetricPolicyPage.preload,
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

const preloadRoutes = buildAuthenticatedRoutes('UTC');

/**
 * Warms the page chunk of `path` (navigation intent, or the URL on first load)
 * so the render that needs it finds it ready. Unknown paths are ignored.
 */
export function preloadRoute(path: string): void {
  if (path === '/') {
    void DashboardPage.preload();
    return;
  }
  void matchAuthenticatedRoute(preloadRoutes, path)?.route.preload?.();
}
