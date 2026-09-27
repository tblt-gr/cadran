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
    href: '/transactions',
    icon: 'transactions',
    labelKey: 'navigation.transactions',
    match: (path) =>
      [
        '/transactions',
        '/transactions/recurrences',
        '/transactions/categories',
        '/transactions/categories/rules',
      ].includes(path),
    mobile: true,
  },
  {
    href: '/budget',
    icon: 'budget',
    labelKey: 'navigation.budget',
    match: (path) => path === '/budget' || path.startsWith('/budget/'),
  },
  {
    href: '/accounts',
    icon: 'accounts',
    labelKey: 'navigation.accounts',
    match: (path) => path === '/accounts' || /^\/accounts\/[^/]+$/.test(path),
    mobile: true,
  },
  {
    href: '/portfolios/demo',
    icon: 'investments',
    labelKey: 'navigation.investments',
    match: (path) => /^\/portfolios\/[^/]+$/.test(path) || /^\/life-insurance\/[^/]+$/.test(path),
  },
  {
    href: '/goals',
    icon: 'goals',
    labelKey: 'navigation.goals',
    match: (path) => path === '/goals',
  },
  {
    href: '/tax/2026',
    icon: 'tax',
    labelKey: 'navigation.tax',
    match: (path) => /^\/tax\/\d{4}$/.test(path),
  },
  {
    href: '/reports',
    icon: 'transactions',
    labelKey: 'navigation.reports',
    match: (path) =>
      path === '/reports' ||
      /^\/reports\/annual\/\d{4}$/.test(path) ||
      path === '/reports/all-years',
  },
  {
    href: '/catalog',
    icon: 'catalog',
    labelKey: 'navigation.catalog',
    match: (path) => path === '/catalog',
  },
  {
    href: '/product-models',
    icon: 'catalog',
    labelKey: 'navigation.productModels',
    match: (path) => path === '/product-models',
  },
  {
    href: '/settings/profile',
    icon: 'settings',
    labelKey: 'navigation.settings',
    match: (path) => path.startsWith('/settings/'),
  },
];

export function getRouteTitleKey(pathname: string): ParseKeys {
  if (pathname === '/categories/rules' || pathname === '/transactions/categories/rules')
    return 'routes.categorizationRules';
  if (pathname === '/categories' || pathname === '/transactions/categories')
    return 'routes.categories';
  if (pathname === '/transactions/recurrences') return 'routes.recurrences';
  if (pathname === '/accounts/groups') return 'navigation.accountGroups';
  if (/^\/accounts\/(?!groups$)[^/]+$/.test(pathname)) return 'routes.accountDetail';
  if (/^\/life-insurance\/[^/]+$/.test(pathname)) return 'routes.lifeInsurance';
  if (/^\/reports\/annual\/\d{4}$/.test(pathname)) return 'routes.annualReport';
  if (pathname === '/reports/all-years') return 'routes.allYearsReport';
  if (/^\/budget\/plans\/[^/]+$/.test(pathname)) return 'routes.budgetPlan';
  if (pathname === '/budget/plans') return 'routes.budgetPlans';
  if (pathname === '/settings/metric-policy') return 'routes.metricPolicy';

  return navigationItems.find((item) => item.match(pathname))?.labelKey ?? 'routes.notFound';
}
