import { cleanup, render } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { RouteRedirect } from './RouteRedirect';

afterEach(() => {
  cleanup();
  window.history.replaceState({}, '', '/');
});

describe('RouteRedirect', () => {
  it('keeps the whole query string and hash by default', () => {
    window.history.replaceState({}, '', '/old?a=1&b=2#x');
    render(<RouteRedirect to="/new" />);
    expect(window.location.pathname + window.location.search + window.location.hash).toBe(
      '/new?a=1&b=2#x',
    );
  });

  it('copies only the listed parameters and drops the hash when keepParams is given', () => {
    const before = window.history.length;
    window.history.replaceState({}, '', '/old?q=world&status=all&foo=1#x');
    render(<RouteRedirect keepParams={['q', 'status']} to="/new" />);
    expect(window.location.pathname + window.location.search + window.location.hash).toBe(
      '/new?q=world&status=all',
    );
    expect(window.history.length).toBe(before);
  });
});
