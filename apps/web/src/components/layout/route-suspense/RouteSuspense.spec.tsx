import { cleanup, render, screen } from '@testing-library/react';
import { lazy } from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { RouteSuspense } from './RouteSuspense';

const PendingPage = lazy(() => new Promise<never>(() => undefined));

describe('RouteSuspense', () => {
  afterEach(cleanup);

  it('shows the route title as a real heading and announces the loading once', () => {
    render(
      <RouteSuspense titleKey="navigation.goals">
        <PendingPage />
      </RouteSuspense>,
    );

    expect(screen.getByRole('heading', { level: 2, name: 'Objectifs' })).toBeTruthy();
    expect(screen.getAllByRole('status')).toHaveLength(1);
    expect(screen.getByRole('status').textContent).toBe('Chargement de la page…');
  });

  it('keeps the loading region busy and hides the decorative blocks from assistive technology', () => {
    const { container } = render(
      <RouteSuspense titleKey="navigation.goals">
        <PendingPage />
      </RouteSuspense>,
    );

    expect(container.querySelector('[aria-busy="true"]')).not.toBeNull();
    expect(container.querySelectorAll('[aria-hidden="true"]').length).toBeGreaterThan(0);
  });

  it('renders the page itself, without any fallback, once nothing is pending', () => {
    render(
      <RouteSuspense titleKey="navigation.goals">
        <p>Contenu prêt</p>
      </RouteSuspense>,
    );

    expect(screen.getByText('Contenu prêt')).toBeTruthy();
    expect(screen.queryByRole('status')).toBeNull();
  });
});
