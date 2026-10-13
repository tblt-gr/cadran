import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { RouteErrorBoundary } from './RouteErrorBoundary';

function Broken(): never {
  throw new Error('chunk failed');
}

const fallback = <p role="alert">Page indisponible</p>;

describe('RouteErrorBoundary', () => {
  beforeEach(() => {
    // React logs every error it hands to a boundary.
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
  });
  afterEach(cleanup);

  it('shows its fallback instead of the page that failed', () => {
    render(
      <RouteErrorBoundary fallback={fallback} resetKey="/goals">
        <Broken />
      </RouteErrorBoundary>,
    );

    expect(screen.getByRole('alert').textContent).toBe('Page indisponible');
  });

  it('renders its children untouched when nothing fails', () => {
    render(
      <RouteErrorBoundary fallback={fallback} resetKey="/goals">
        <p>Contenu</p>
      </RouteErrorBoundary>,
    );

    expect(screen.getByText('Contenu')).toBeTruthy();
    expect(screen.queryByRole('alert')).toBeNull();
  });

  it('keeps the fallback while the key is unchanged, then retries when it changes', () => {
    const { rerender } = render(
      <RouteErrorBoundary fallback={fallback} resetKey="/goals">
        <Broken />
      </RouteErrorBoundary>,
    );

    rerender(
      <RouteErrorBoundary fallback={fallback} resetKey="/goals">
        <p>Contenu</p>
      </RouteErrorBoundary>,
    );
    expect(screen.getByRole('alert')).toBeTruthy();

    rerender(
      <RouteErrorBoundary fallback={fallback} resetKey="/reports">
        <p>Contenu</p>
      </RouteErrorBoundary>,
    );
    expect(screen.queryByRole('alert')).toBeNull();
    expect(screen.getByText('Contenu')).toBeTruthy();
  });

  it('shows the fallback for the new key when the page it navigated to fails too', () => {
    const { rerender } = render(
      <RouteErrorBoundary fallback={fallback} resetKey="/goals">
        <p>Contenu</p>
      </RouteErrorBoundary>,
    );

    rerender(
      <RouteErrorBoundary fallback={fallback} resetKey="/reports">
        <Broken />
      </RouteErrorBoundary>,
    );

    expect(screen.getByRole('alert')).toBeTruthy();
  });
});
