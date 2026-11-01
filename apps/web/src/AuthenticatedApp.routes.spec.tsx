import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ReactElement } from 'react';
import '@/i18n';
import { RouteRedirect } from '@/components/layout/route-redirect/RouteRedirect';
import {
  buildAuthenticatedRoutes,
  matchAuthenticatedRoute,
  preloadRoute,
} from './AuthenticatedApp.routes';

const loaded = vi.hoisted(() => ({ transactions: 0, dashboard: 0 }));

// Counting factory runs tells whether a page chunk was requested, without rendering it.
vi.mock('@/features/transactions/TransactionsPage', () => {
  loaded.transactions += 1;
  return { TransactionsPage: () => null };
});
vi.mock('@/features/dashboard/DashboardPage', () => {
  loaded.dashboard += 1;
  return { DashboardPage: () => null };
});

const routes = buildAuthenticatedRoutes('Europe/Paris');

describe('AuthenticatedApp route table', () => {
  it('resolves every static and parameterized route to exactly one entry', () => {
    const paths = [
      '/accounts',
      '/accounts/groups',
      '/accounts/00000000-0000-7000-8000-000000000001',
      '/portfolios/instruments',
      '/portfolios/00000000-0000-7000-8000-000000000001',
      '/transactions/categories',
      '/transactions/categories/rules',
      '/categories',
      '/categories/rules',
      '/catalog',
      '/product-models',
      '/parameters/catalog',
      '/parameters/product-models',
      '/transactions',
      '/transactions/recurrences',
      '/budget',
      '/budget/plans',
      '/budget/plans/00000000-0000-7000-8000-000000000001',
      '/budget/00000000-0000-7000-8000-000000000001',
      '/budget/2026',
      '/budget/2026/03',
      '/settings/profile',
      '/settings/metric-policy',
      '/parameters/profile',
      '/parameters/metric-policy',
    ];

    for (const path of paths) {
      expect(matchAuthenticatedRoute(routes, path), path).not.toBeNull();
    }
  });

  it('checks /accounts/groups before the generic account-id pattern it would otherwise match', () => {
    const matched = matchAuthenticatedRoute(routes, '/accounts/groups');
    // A false match here would send "groups" into AccountDetailPage as an id.
    expect(matched?.match[1]).toBeUndefined();
  });

  it('redirects the retired /portfolios overview to the accounts overview', () => {
    const matched = matchAuthenticatedRoute(routes, '/portfolios');
    expect(matched?.route.redirect).toBe(true);
    expect((matched!.route.render(matched!.match) as ReactElement<{ to: string }>).props.to).toBe(
      '/accounts',
    );
  });

  it('keeps /portfolios/instruments out of the portfolio account pattern', () => {
    const matched = matchAuthenticatedRoute(routes, '/portfolios/instruments');
    expect(matched?.match[1]).toBeUndefined();
    const account = matchAuthenticatedRoute(routes, '/portfolios/abc');
    expect(account?.match[1]).toBe('abc');
  });

  it('does not match the dashboard root or an unrelated path', () => {
    expect(matchAuthenticatedRoute(routes, '/')).toBeNull();
    expect(matchAuthenticatedRoute(routes, '/does-not-exist')).toBeNull();
  });

  it('matches an unmatched path under /budget via the catch-all', () => {
    expect(matchAuthenticatedRoute(routes, '/budget/plans/one/two')).not.toBeNull();
    expect(matchAuthenticatedRoute(routes, '/unknown/deep/path')).toBeNull();
  });

  it('redirects the legacy catalog, product model and settings paths under /parameters', () => {
    for (const path of ['/catalog', '/product-models', '/settings/profile']) {
      expect(matchAuthenticatedRoute(routes, path)?.route.redirect, path).toBe(true);
    }
  });

  it('serves no /reports route and no legacy /budget/YYYY-MM route: both fall to the 404 catch-all', () => {
    for (const path of ['/reports', '/reports/annual/2026', '/reports/all-years']) {
      expect(matchAuthenticatedRoute(routes, path), path).toBeNull();
    }
    for (const path of ['/budget/2026-03', '/budget/2026/03/01', '/budget/2026/abc']) {
      const matched = matchAuthenticatedRoute(routes, path);
      expect(matched?.route.pattern.source, path).toBe('^\\/budget\\/');
    }
  });

  it('routes a bare UUID under /budget to the plan detail redirect, distinct from a month key', () => {
    const uuidMatch = matchAuthenticatedRoute(
      routes,
      '/budget/00000000-0000-7000-8000-000000000001',
    );
    const monthMatch = matchAuthenticatedRoute(routes, '/budget/2026/03');

    expect(uuidMatch?.match[1]).toBe('00000000-0000-7000-8000-000000000001');
    expect(monthMatch?.match[1]).toBe('2026');
    expect(monthMatch?.match[2]).toBe('03');
    // The two entries are distinct routes even though both live under /budget/:x.
    expect(uuidMatch?.route).not.toBe(monthMatch?.route);
  });

  it('captures the account id and the plan id from their parameterized routes', () => {
    const accountMatch = matchAuthenticatedRoute(
      routes,
      '/accounts/00000000-0000-7000-8000-000000000002',
    );
    const planMatch = matchAuthenticatedRoute(
      routes,
      '/budget/plans/00000000-0000-7000-8000-000000000003',
    );

    expect(accountMatch?.match[1]).toBe('00000000-0000-7000-8000-000000000002');
    expect(planMatch?.match[1]).toBe('00000000-0000-7000-8000-000000000003');
  });

  describe('page chunks', () => {
    it('gives every route that shows a page a preloader, and plain redirects none', () => {
      const withoutPreload = routes.filter((route) => !route.redirect && !route.preload);
      // Only the catch-all 404 placeholder is static.
      expect(withoutPreload.map((route) => route.pattern.source)).toEqual(['^\\/budget\\/']);
    });

    it('requests the matched page chunk only when preloaded, never at import time', async () => {
      expect(loaded.transactions).toBe(0);

      preloadRoute('/transactions');

      await vi.waitFor(() => expect(loaded.transactions).toBe(1));
    });

    it('preloads the dashboard for the root path, which has no route entry', async () => {
      expect(loaded.dashboard).toBe(0);

      preloadRoute('/');

      await vi.waitFor(() => expect(loaded.dashboard).toBe(1));
    });

    it('ignores a path no page serves', () => {
      expect(() => preloadRoute('/does-not-exist')).not.toThrow();
    });
  });

  describe('annual report year guard', () => {
    afterEach(() => {
      cleanup();
      vi.useRealTimers();
      window.history.replaceState({}, '', '/');
    });

    function redirectTarget(path: string): string | null {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-09-24T10:00:00Z') });
      window.history.replaceState({}, '', path);
      const matched = matchAuthenticatedRoute(routes, path)!;
      render(matched.route.render(matched.match));
      return window.location.pathname === path ? null : window.location.pathname;
    }

    it.each(['/budget/2027', '/budget/1899'])(
      'replaces %s with the current workspace year',
      (path) => {
        expect(redirectTarget(path)).toBe('/budget/2026');
      },
    );

    it('keeps a valid empty past year instead of redirecting', () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-09-24T10:00:00Z') });
      const matched = matchAuthenticatedRoute(routes, '/budget/2001')!;
      const element = matched.route.render(matched.match) as ReactElement<{ year: number }>;
      expect(element.type).not.toBe(RouteRedirect);
      expect(element.props.year).toBe(2001);
    });
  });

  describe('budget month clamping', () => {
    afterEach(() => {
      cleanup();
      vi.useRealTimers();
      window.history.replaceState({}, '', '/');
    });

    function redirectTarget(path: string): string | null {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-09-24T10:00:00Z') });
      window.history.replaceState({}, '', path);
      const matched = matchAuthenticatedRoute(routes, path)!;
      render(matched.route.render(matched.match));
      return window.location.pathname === path ? null : window.location.pathname;
    }

    it.each([
      ['/budget/2025/00', '/budget/2025/01'],
      ['/budget/2025/13', '/budget/2025/12'],
      ['/budget/2026/13', '/budget/2026/09'],
      ['/budget/2026/0', '/budget/2026/01'],
      ['/budget/2026/7', '/budget/2026/07'],
    ])('replaces %s with %s', (path, expected) => {
      expect(redirectTarget(path)).toBe(expected);
    });

    it('sends the bare /budget to the current month', () => {
      expect(redirectTarget('/budget')).toBe('/budget/2026/09');
    });

    it('keeps the query string of a clamped link', () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-09-24T10:00:00Z') });
      window.history.replaceState({}, '', '/budget/2025/13?axis=FIXED');
      const matched = matchAuthenticatedRoute(routes, '/budget/2025/13')!;
      render(matched.route.render(matched.match));
      expect(window.location.pathname + window.location.search).toBe('/budget/2025/12?axis=FIXED');
    });

    it('renders the monthly page for a valid month key', () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-09-24T10:00:00Z') });
      const matched = matchAuthenticatedRoute(routes, '/budget/2026/03')!;
      const element = matched.route.render(matched.match) as ReactElement<{ periodKey: string }>;
      expect(element.props.periodKey).toBe('2026-03');
    });
  });
});
