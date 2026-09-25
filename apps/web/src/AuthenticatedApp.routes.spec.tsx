import { describe, expect, it } from 'vitest';
import { buildAuthenticatedRoutes, matchAuthenticatedRoute } from './AuthenticatedApp.routes';

const routes = buildAuthenticatedRoutes('Europe/Paris');

describe('AuthenticatedApp route table', () => {
  it('resolves every static and parameterized route to exactly one entry', () => {
    const paths = [
      '/accounts',
      '/accounts/groups',
      '/accounts/00000000-0000-7000-8000-000000000001',
      '/transactions/categories',
      '/transactions/categories/rules',
      '/categories',
      '/categories/rules',
      '/catalog',
      '/product-models',
      '/transactions',
      '/transactions/recurrences',
      '/budget',
      '/budget/plans',
      '/budget/plans/00000000-0000-7000-8000-000000000001',
      '/reports',
      '/budget/00000000-0000-7000-8000-000000000001',
      '/budget/2026-03',
      '/settings/profile',
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

  it('does not match the dashboard root or an unrelated path', () => {
    expect(matchAuthenticatedRoute(routes, '/')).toBeNull();
    expect(matchAuthenticatedRoute(routes, '/does-not-exist')).toBeNull();
  });

  it('still hides global actions for an unmatched path under /budget, via the catch-all', () => {
    // Regression: every /budget-prefixed path used to hide the shell's global
    // actions, including a 404 one segment too deep for any specific route
    // (e.g. a stale bookmark). AuthenticatedApp falls back to `true` only when
    // no entry matches at all, so an unmatched /budget/... path needs its own
    // entry rather than relying on that fallback.
    const matched = matchAuthenticatedRoute(routes, '/budget/plans/one/two');
    expect(matched).not.toBeNull();
    expect(matched?.route.globalActions).toBe(false);

    // An unrelated unmatched path is not under /budget: it correctly falls
    // through to AuthenticatedApp's own 404 branch, which defaults to true.
    expect(matchAuthenticatedRoute(routes, '/unknown/deep/path')).toBeNull();
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

  it('hides the shell global actions for every declared route', () => {
    for (const route of routes) {
      expect(route.globalActions).toBe(false);
    }
  });
});
