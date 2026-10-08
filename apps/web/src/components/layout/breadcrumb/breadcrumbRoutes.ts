import type { ParseKeys } from 'i18next';

export interface BreadcrumbItem {
  label: string;
  href?: string;
  pending?: boolean;
}

export function breadcrumbItems(
  path: string,
  t: (key: ParseKeys) => string,
  account: { label: string | null; pending: boolean } | null,
): BreadcrumbItem[] {
  const home = { label: t('breadcrumb.home'), href: '/' };
  const item = (labelKey: ParseKeys, href?: string): BreadcrumbItem => ({
    label: t(labelKey),
    href,
  });
  const accounts = item('navigation.accounts', '/accounts');
  const transactions = item('navigation.transactions', '/transactions');
  const categories = item('routes.categories', '/transactions/categories');
  const budget = item('navigation.budget', '/budget');
  const plans = item('routes.budgetPlans', '/budget/plans');
  const reports = item('navigation.reports', '/reports');
  const investments = item('navigation.investments', '/portfolios');
  const settings = item('navigation.settings', '/settings/profile');
  const staticRoutes: Record<string, BreadcrumbItem[]> = {
    '/': [],
    '/accounts': [accounts],
    '/accounts/groups': [accounts, item('navigation.accountGroups')],
    '/portfolios': [investments],
    '/portfolios/instruments': [investments, item('routes.instruments')],
    '/transactions': [transactions],
    '/transactions/recurrences': [transactions, item('routes.recurrences')],
    '/transactions/categories': [transactions, categories],
    '/transactions/categories/rules': [
      transactions,
      categories,
      item('routes.categorizationRules'),
    ],
    '/categories': [transactions, categories],
    '/categories/rules': [transactions, categories, item('routes.categorizationRules')],
    '/budget': [budget],
    '/budget/plans': [budget, plans],
    '/reports': [reports],
    '/reports/all-years': [reports, item('routes.allYearsReport')],
    '/goals': [item('navigation.goals')],
    '/catalog': [item('navigation.catalog')],
    '/product-models': [item('navigation.productModels')],
    '/settings/profile': [settings, item('settings.tabs.profile')],
    '/settings/metric-policy': [settings, item('routes.metricPolicy')],
  };
  let trail = staticRoutes[path];
  if (!trail) {
    const accountRoute = /^\/(accounts|portfolios)\/([^/]+)$/.exec(path);
    if (accountRoute) {
      const label =
        account?.label ??
        t(account?.pending === false ? 'breadcrumb.account' : 'breadcrumb.loadingAccount');
      const accountItem = {
        label,
        href: `/accounts/${accountRoute[2]}`,
        pending: account?.pending !== false,
      };
      trail = [accounts, accountItem];
      if (accountRoute[1] === 'portfolios') trail.push(item('routes.portfolioAccount'));
    } else if (/^\/budget\/plans\/[^/]+$/.test(path)) {
      trail = [budget, plans, item('routes.budgetPlan')];
    } else if (/^\/budget\/[^/]+$/.test(path)) {
      trail = [budget, { label: path.split('/')[2]! }];
    } else if (/^\/reports\/annual\/[^/]+$/.test(path)) {
      trail = [reports, { label: `${t('routes.annualReport')} ${path.split('/')[3]}` }];
    } else if (/^\/tax\/\d{4}$/.test(path)) {
      trail = [item('navigation.tax')];
    } else {
      trail = [item('routes.notFound')];
    }
  }
  return [home, ...trail].map((entry, index) =>
    index === trail.length ? { ...entry, href: undefined } : entry,
  );
}
