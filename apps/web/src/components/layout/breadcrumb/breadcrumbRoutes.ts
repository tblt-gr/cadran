import type { ParseKeys } from 'i18next';

export interface BreadcrumbItem {
  label: string;
  href?: string;
  pending?: boolean;
}

/** Capitalised month name of a two-digit month number. */
function monthName(month: string, locale: string): string {
  const name = new Intl.DateTimeFormat(locale, { month: 'long', timeZone: 'UTC' }).format(
    new Date(Date.UTC(2020, Number(month) - 1, 1)),
  );
  return name.charAt(0).toLocaleUpperCase(locale) + name.slice(1);
}

export function breadcrumbItems(
  path: string,
  t: (key: ParseKeys) => string,
  account: { label: string | null; pending: boolean } | null,
  locale = 'fr',
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
  const settings = item('navigation.settings', '/parameters/profile');
  const staticRoutes: Record<string, BreadcrumbItem[]> = {
    '/': [],
    '/accounts': [accounts],
    '/accounts/groups': [accounts, item('navigation.accountGroups')],
    '/portfolios/instruments': [accounts, item('routes.instruments')],
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
    '/goals': [item('navigation.goals')],
    '/parameters/profile': [settings, item('settings.tabs.profile')],
    '/parameters/metric-policy': [settings, item('routes.metricPolicy')],
    '/parameters/catalog': [settings, item('navigation.catalog')],
    '/parameters/product-models': [settings, item('navigation.productModels')],
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
    } else if (/^\/budget\/plans\/[^/]+$/.test(path)) {
      trail = [budget, plans, item('routes.budgetPlan')];
    } else if (/^\/budget\/\d{4}\/\d{2}$/.test(path)) {
      const [, , year, month] = path.split('/');
      trail = [
        budget,
        { label: year!, href: `/budget/${year}` },
        { label: monthName(month!, locale) },
      ];
    } else if (/^\/budget\/\d{4}$/.test(path)) {
      const year = path.split('/')[2]!;
      trail = [budget, { label: year, href: `/budget/${year}` }, item('routes.annualReport')];
    } else {
      trail = [item('routes.notFound')];
    }
  }
  return [home, ...trail].map((entry, index) =>
    index === trail.length ? { ...entry, href: undefined } : entry,
  );
}
