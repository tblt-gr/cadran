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
    match: (path) => path === '/transactions',
    mobile: true,
  },
  {
    href: '/months/2026-03',
    icon: 'budget',
    labelKey: 'navigation.budget',
    match: (path) => /^\/months\/\d{4}-(0[1-9]|1[0-2])$/.test(path),
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
    href: '/reports/annual/2026',
    icon: 'transactions',
    labelKey: 'navigation.reports',
    match: (path) => /^\/reports\/annual\/\d{4}$/.test(path) || path === '/reports/all-years',
  },
  {
    href: '/settings/profile',
    icon: 'settings',
    labelKey: 'navigation.settings',
    match: (path) => path.startsWith('/settings/'),
  },
];

export function getRouteTitleKey(pathname: string): ParseKeys {
  if (/^\/accounts\/[^/]+$/.test(pathname)) return 'routes.accountDetail';
  if (/^\/life-insurance\/[^/]+$/.test(pathname)) return 'routes.lifeInsurance';
  if (pathname === '/reports/all-years') return 'routes.allYearsReport';

  return navigationItems.find((item) => item.match(pathname))?.labelKey ?? 'routes.notFound';
}
