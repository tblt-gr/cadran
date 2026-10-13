import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { SessionProbeSkeleton } from './SessionProbeSkeleton';

// A chunk that cannot be fetched, as after an upgrade removed it from the server.
vi.mock('@/features/recurrences/RecurrencesPage', () => {
  throw new Error('Failed to fetch dynamically imported module');
});

describe('SessionProbeSkeleton', () => {
  beforeEach(() => {
    // React logs every error it hands to a boundary.
    vi.spyOn(console, 'error').mockImplementation(() => undefined);
  });

  afterEach(() => {
    cleanup();
    vi.restoreAllMocks();
    window.history.replaceState({}, '', '/');
  });

  it('keeps the shell and shows the load error when the page chunk is missing during the probe', async () => {
    window.history.replaceState({}, '', '/transactions/recurrences');

    render(<SessionProbeSkeleton />);

    expect((await screen.findByRole('alert')).textContent).toContain(
      'La page n’a pas pu être chargée',
    );
    expect(screen.getByRole('button', { name: 'Recharger la page' })).toBeTruthy();
    expect(screen.getByRole('navigation', { name: 'Navigation principale' })).toBeTruthy();
  });
});
