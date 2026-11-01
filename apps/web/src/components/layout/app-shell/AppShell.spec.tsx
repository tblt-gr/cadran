import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { AppShell } from './AppShell';

function renderShell() {
  return render(
    <AppShell
      freshnessLabel="Mis à jour il y a 4 jours"
      headerDate="Samedi 29 août"
      path="/"
      setPath={vi.fn()}
    >
      <p>Contenu</p>
    </AppShell>,
  );
}

describe('AppShell', () => {
  afterEach(() => {
    cleanup();
  });

  it('preserves named link order in the sidebar, compact rail and mobile navigation', () => {
    renderShell();
    const sidebar = screen.getByRole('navigation', { name: 'Navigation principale' });
    const expectedLabels = [
      'Synthèse',
      'Comptes',
      'Budget',
      'Objectifs',
      'Transactions',
      'Paramètres',
    ];
    const labels = (container: HTMLElement) =>
      within(container)
        .getAllByRole('link')
        .map((link) => link.textContent);
    expect(labels(sidebar)).toEqual(expectedLabels);
    expect(
      within(sidebar)
        .getAllByRole('link')
        .every((link) => link.tabIndex === 0),
    ).toBe(true);

    fireEvent.click(screen.getByRole('button', { name: 'Réduire la navigation' }));
    expect(labels(sidebar)).toEqual(expectedLabels);
    expectedLabels.forEach((name) =>
      expect(within(sidebar).getByRole('link', { name })).toBeTruthy(),
    );

    const mobile = screen.getByRole('navigation', { name: 'Navigation mobile' });
    expect(labels(mobile)).toEqual(['Synthèse', 'Comptes', 'Budget', 'Transactions']);
    fireEvent.click(within(mobile).getByRole('button', { name: 'Plus' }));
    const sheet = screen.getByRole('dialog', { name: 'Navigation complémentaire' });
    expect(labels(sheet)).toEqual(['Objectifs', 'Paramètres']);
  });

  it.each([
    ['loading', true, ''],
    ['empty', false, 'Aucune donnée'],
    ['error', false, 'Lecture impossible'],
    ['non-calculable', false, 'Non calculable'],
  ])('keeps navigation selection independent of %s page content', (_state, busy, content) => {
    render(
      <AppShell isNavigating={busy} path="/life-insurance/contract-id" setPath={vi.fn()}>
        <p>{content}</p>
      </AppShell>,
    );
    const sidebar = screen.getByRole('navigation', { name: 'Navigation principale' });
    expect(
      within(sidebar)
        .getAllByRole('link')
        .map((link) => link.getAttribute('href')),
    ).toEqual(['/', '/accounts', '/budget', '/goals', '/transactions', '/parameters/profile']);
    expect(within(sidebar).getByRole('link', { current: 'page' }).textContent).toBe('Comptes');
    const mobile = screen.getByRole('navigation', { name: 'Navigation mobile' });
    expect(within(mobile).getByRole('link', { current: 'page' }).textContent).toBe('Comptes');
    expect(
      within(mobile).getByRole('button', { name: 'Plus' }).getAttribute('aria-current'),
    ).toBeNull();
  });

  it('closes the complementary navigation and restores focus to its trigger', async () => {
    renderShell();

    const moreButton = screen.getByRole('button', { name: 'Plus' });
    fireEvent.click(moreButton);
    const sheet = screen.getByRole('dialog', { name: 'Navigation complémentaire' });
    expect(within(sheet).getByRole('link', { name: 'Objectifs' })).toBeTruthy();

    fireEvent.click(within(sheet).getByRole('button', { name: 'Fermer' }));

    expect(screen.queryByRole('dialog', { name: 'Navigation complémentaire' })).toBeNull();
    await waitFor(() => expect(document.activeElement).toBe(moreButton));
  });

  it('renders page actions inside the header and no global placeholders', () => {
    render(
      <AppShell path="/transactions" setPath={vi.fn()}>
        <HeaderActions>
          <button type="button">Action de page</button>
        </HeaderActions>
      </AppShell>,
    );

    const header = screen.getByRole('banner');
    expect(within(header).getByRole('button', { name: 'Action de page' })).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Rechercher' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Ajouter' })).toBeNull();
  });

  it('hides dashboard-only context and global placeholders on feature routes', () => {
    render(
      <AppShell path="/transactions/categories" setPath={vi.fn()}>
        <p>Catégories</p>
      </AppShell>,
    );

    expect(screen.queryByText('Mis à jour il y a 4 jours')).toBeNull();
    expect(screen.queryByText('Samedi 29 août')).toBeNull();
    expect(screen.queryByRole('button', { name: 'Rechercher' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Ajouter' })).toBeNull();
  });

  it('forwards navigation intent from the sidebar and the complementary sheet', () => {
    const onNavigationIntent = vi.fn();
    render(
      <AppShell onNavigationIntent={onNavigationIntent} path="/" setPath={vi.fn()}>
        <p>Contenu</p>
      </AppShell>,
    );

    const sidebar = screen.getByRole('navigation', { name: 'Navigation principale' });
    fireEvent.focus(within(sidebar).getByRole('link', { name: 'Objectifs' }));
    expect(onNavigationIntent).toHaveBeenLastCalledWith('/goals');

    fireEvent.click(screen.getByRole('button', { name: 'Plus' }));
    const sheet = screen.getByRole('dialog', { name: 'Navigation complémentaire' });
    fireEvent.pointerEnter(within(sheet).getByRole('link', { name: 'Paramètres' }));
    expect(onNavigationIntent).toHaveBeenLastCalledWith('/parameters/profile');
  });

  it('marks the content busy only while navigating', () => {
    const { rerender } = render(
      <AppShell path="/" setPath={vi.fn()}>
        <p>Contenu</p>
      </AppShell>,
    );
    const main = screen.getByRole('main');
    expect(main.getAttribute('aria-busy')).toBeNull();

    rerender(
      <AppShell isNavigating path="/" setPath={vi.fn()}>
        <p>Contenu</p>
      </AppShell>,
    );

    expect(main.getAttribute('aria-busy')).toBe('true');
  });
});
