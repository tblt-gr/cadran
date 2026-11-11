import type { ParseKeys } from 'i18next';
import type { IconName } from '@/components/ui/icon/Icon';

export interface NavigationItem {
  href: string;
  icon: IconName;
  labelKey: ParseKeys;
  match: (pathname: string) => boolean;
  mobile?: boolean;
}

export const navigationItems: NavigationItem[] = [
  {
    href: '/',
    icon: 'home',
    labelKey: 'navigation.home',
    match: (path) => path === '/',
    mobile: true,
  },
  {
    href: '/budget',
    icon: 'budget',
    labelKey: 'navigation.budget',
    // Transactions, categories and recurrences are reached from the Budget, which stays active.
    match: (path) =>
      path === '/budget' ||
      path.startsWith('/budget/') ||
      [
        '/transactions',
        '/transactions/recurrences',
        '/transactions/categories',
        '/transactions/categories/rules',
      ].includes(path),
    mobile: true,
  },
  {
    href: '/accounts',
    icon: 'accounts',
    labelKey: 'navigation.accounts',
    // Investment accounts are reached from the accounts overview.
    match: (path) =>
      path === '/accounts' ||
      /^\/accounts\/[^/]+$/.test(path) ||
      path.startsWith('/portfolios/') ||
      /^\/life-insurance\/[^/]+$/.test(path),
    mobile: true,
  },
  {
    href: '/investments',
    icon: 'investments',
    labelKey: 'navigation.investments',
    match: (path) => path === '/investments' || path.startsWith('/investments/'),
  },
  {
    href: '/parameters/interface',
    icon: 'settings',
    labelKey: 'navigation.settings',
    match: (path) => path.startsWith('/parameters/'),
  },
];

export function getRouteTitleKey(pathname: string): ParseKeys {
  if (pathname === '/categories/rules' || pathname === '/transactions/categories/rules')
    return 'routes.categorizationRules';
  if (pathname === '/categories' || pathname === '/transactions/categories')
    return 'routes.categories';
  if (pathname === '/transactions') return 'routes.transactions';
  if (pathname === '/transactions/recurrences') return 'routes.recurrences';
  if (pathname === '/accounts/groups') return 'navigation.accountGroups';
  if (/^\/accounts\/(?!groups$)[^/]+$/.test(pathname)) return 'routes.accountDetail';
  if (/^\/life-insurance\/[^/]+$/.test(pathname)) return 'routes.lifeInsurance';
  if (pathname === '/investments/instruments') return 'routes.instruments';
  if (/^\/portfolios\/[^/]+$/.test(pathname)) return 'routes.portfolioAccount';
  if (/^\/budget\/\d{4}$/.test(pathname)) return 'routes.annualReport';
  if (pathname === '/parameters/metric-policy') return 'routes.metricPolicy';
  if (pathname === '/parameters/catalog') return 'navigation.catalog';
  if (pathname === '/parameters/product-models') return 'navigation.productModels';

  return navigationItems.find((item) => item.match(pathname))?.labelKey ?? 'routes.notFound';
}
