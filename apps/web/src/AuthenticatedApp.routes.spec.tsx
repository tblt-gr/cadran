import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { ReactElement } from 'react';
import '@/i18n';
import { AnnualReportPage } from '@/features/reports/annual/AnnualReportPage';
import { buildAuthenticatedRoutes, matchAuthenticatedRoute } from './AuthenticatedApp.routes';

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
      '/reports',
      '/reports/all-years',
      '/budget/00000000-0000-7000-8000-000000000001',
      '/budget/2026-03',
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

  it('routes a bare UUID under /budget to the plan detail redirect, distinct from a month key', () => {
    const uuidMatch = matchAuthenticatedRoute(
      routes,
      '/budget/00000000-0000-7000-8000-000000000001',
    );
    const monthMatch = matchAuthenticatedRoute(routes, '/budget/2026-03');

    expect(uuidMatch?.match[1]).toBe('00000000-0000-7000-8000-000000000001');
    expect(monthMatch?.match[1]).toBe('2026-03');
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

    it.each(['/reports/annual/2027', '/reports/annual/1899', '/reports/annual/abc'])(
      'replaces %s with the current workspace year',
      (path) => {
        expect(redirectTarget(path)).toBe('/reports/annual/2026');
      },
    );

    it('keeps a valid empty past year', () => {
      vi.useFakeTimers({ toFake: ['Date'], now: new Date('2026-09-24T10:00:00Z') });
      const matched = matchAuthenticatedRoute(routes, '/reports/annual/2001')!;
      const element = matched.route.render(matched.match) as ReactElement;
      expect(element.type).toBe(AnnualReportPage);
    });
  });
});
