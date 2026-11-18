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
  const investments = item('navigation.investments', '/investments');
  const transactions = item('navigation.transactions', '/transactions');
  const categories = item('routes.categories', '/transactions/categories');
  const budget = item('navigation.budget', '/budget');
  const settings = item('navigation.settings', '/parameters/interface');
  const staticRoutes: Record<string, BreadcrumbItem[]> = {
    '/': [],
    '/accounts': [accounts],
    '/accounts/groups': [accounts, item('navigation.accountGroups')],
    '/investments': [investments],
    '/investments/instruments': [investments, item('routes.instruments')],
    '/transactions': [budget, transactions],
    '/transactions/recurrences': [budget, transactions, item('routes.recurrences')],
    '/transactions/categories': [budget, transactions, categories],
    '/transactions/categories/rules': [
      budget,
      transactions,
      categories,
      item('routes.categorizationRules'),
    ],
    '/categories': [budget, transactions, categories],
    '/categories/rules': [budget, transactions, categories, item('routes.categorizationRules')],
    '/budget': [budget],
    '/parameters/interface': [settings, item('settings.tabs.interface')],
    '/parameters/profile': [settings, item('settings.tabs.profile')],
    '/parameters/metric-policy': [settings, item('routes.metricPolicy')],
    '/parameters/catalog': [settings, item('navigation.catalog')],
    '/parameters/product-models': [settings, item('navigation.productModels')],
  };
  let trail = staticRoutes[path];
  if (!trail) {
    const accountRoute = /^\/(accounts|portfolios)\/([^/]+)$/.exec(path);
    if (/^\/investments\/instruments\/[^/]+$/.test(path)) {
      trail = [
        investments,
        item('routes.instruments', '/investments/instruments'),
        { label: account?.label ?? 'Instrument', pending: account?.pending !== false },
      ];
    } else if (accountRoute) {
      const label =
        account?.label ??
        t(account?.pending === false ? 'breadcrumb.account' : 'breadcrumb.loadingAccount');
      const accountItem = {
        label,
        href: `/accounts/${accountRoute[2]}`,
        pending: account?.pending !== false,
      };
      trail = [accounts, accountItem];
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

/**
 * Parent page of the current path: the last linked trail item that is not the page itself.
 * The "Paramètres" item stands for the settings tabs as a whole, so on any `/parameters/*` page it
 * is the page itself and the dashboard is the parent.
 */
export function backTarget(items: BreadcrumbItem[], path: string): BreadcrumbItem | null {
  const inSettings = path.startsWith('/parameters/');
  for (let index = items.length - 1; index >= 0; index -= 1) {
    const item = items[index]!;
    if (!item.href || item.href === path) continue;
    if (inSettings && item.href.startsWith('/parameters/')) continue;
    return item;
  }
  return null;
}

const DETAIL_PATH = new RegExp(
  '^/(?:(?:accounts(?!/groups$)|portfolios(?!/instruments$))/[^/]+|investments/instruments/[^/]+)$',
);

/**
 * Back target of a detail page (account, instrument). The monthly budget and the annual report
 * have no arrow: the other months and years stay reachable from the page itself.
 */
export function detailBackTarget(items: BreadcrumbItem[], path: string): BreadcrumbItem | null {
  return DETAIL_PATH.test(path) ? backTarget(items, path) : null;
}
