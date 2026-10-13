import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { RouteLoadErrorState } from './RouteLoadErrorState';

afterEach(() => {
  cleanup();
  vi.unstubAllGlobals();
});

describe('RouteLoadErrorState', () => {
  it('keeps the route title as a heading and announces the failure', () => {
    render(<RouteLoadErrorState titleKey="navigation.goals" />);

    expect(screen.getByRole('heading', { level: 2, name: 'Objectifs' })).toBeTruthy();
    expect(screen.getByRole('alert').textContent).toContain('La page n’a pas pu être chargée');
  });

  it('reloads the page from the dedicated button', () => {
    const reload = vi.fn();
    vi.stubGlobal('location', { ...window.location, reload });
    render(<RouteLoadErrorState titleKey="navigation.goals" />);

    fireEvent.click(screen.getByRole('button', { name: 'Recharger la page' }));

    expect(reload).toHaveBeenCalledTimes(1);
  });
});
