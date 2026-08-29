import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import './i18n';
import App from './App';

function renderApp() {
  const queryClient = new QueryClient({
    defaultOptions: { queries: { retry: false } },
  });

  return render(
    <QueryClientProvider client={queryClient}>
      <App />
    </QueryClientProvider>,
  );
}

function respondWithReadyFoundation() {
  vi.stubGlobal(
    'fetch',
    vi.fn(async () =>
      Promise.resolve(
        new Response(JSON.stringify({ status: 'ready', apiVersion: 'v1' }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        }),
      ),
    ),
  );
}

describe('App', () => {
  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.history.replaceState({}, '', '/');
  });

  it('keeps the application shell visible while the API request is pending', () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(() => new Promise(() => undefined)),
    );

    renderApp();

    expect(screen.getByRole('navigation', { name: 'Navigation principale' })).toBeTruthy();
    expect(screen.getByRole('status').textContent).toContain('Connexion à l’API');
  });

  it('renders the representative dashboard states and an accessible chart alternative', async () => {
    respondWithReadyFoundation();

    renderApp();

    await screen.findByText('Données anciennes');
    expect(screen.getByRole('heading', { name: 'Synthèse' })).toBeTruthy();
    expect(document.querySelector('.wealth-card__amount')?.textContent).toBe('124 680,00 €');
    expect(screen.getByText('Données anciennes')).toBeTruthy();
    expect(screen.getByText('Non calculable')).toBeTruthy();
    expect(screen.getByText('Aucun élément à traiter')).toBeTruthy();

    fireEvent.click(screen.getByText('Voir les données sous forme de tableau'));
    const dataTable = screen.getByRole('table', { name: 'Évolution du patrimoine' });
    expect(within(dataTable).getByText('Mars 2026')).toBeTruthy();
    expect(dataTable.textContent).toContain('124 680,00 €');

    const donutSegments = Array.from(document.querySelectorAll('.allocation-donut__segment'));
    expect(
      donutSegments.map((segment) => [
        segment.getAttribute('stroke-dasharray'),
        segment.getAttribute('stroke-dashoffset'),
      ]),
    ).toEqual([
      ['48.2 51.8', '0'],
      ['36.8 63.2', '-48.2'],
      ['15 85', '-85'],
    ]);
  });

  it.each([
    ['/', 'Synthèse'],
    ['/transactions', 'Transactions'],
    ['/months/2026-03', 'Budget'],
    ['/accounts', 'Comptes'],
    ['/accounts/demo', 'Détail du compte'],
    ['/portfolios/demo', 'Investissements'],
    ['/life-insurance/demo', 'Assurance-vie'],
    ['/reports/annual/2026', 'Rapports'],
    ['/reports/all-years', 'Rapport pluriannuel'],
    ['/goals', 'Objectifs'],
    ['/tax/2026', 'Impôts'],
    ['/settings/profile', 'Paramètres'],
  ])('scaffolds the route %s', async (path, title) => {
    window.history.replaceState({}, '', path);
    respondWithReadyFoundation();

    renderApp();

    expect(await screen.findByRole('heading', { name: title })).toBeTruthy();
  });

  it('scaffolds application routes with client-side navigation', async () => {
    respondWithReadyFoundation();

    renderApp();
    await screen.findByRole('heading', { name: 'Synthèse' });

    fireEvent.click(screen.getAllByRole('link', { name: 'Transactions' })[0]);

    expect(screen.getByRole('heading', { name: 'Transactions' })).toBeTruthy();
    expect(window.location.pathname).toBe('/transactions');
    await waitFor(() => expect(document.title).toBe('Transactions · Cadran Budget'));
  });

  it('traps focus in the mobile more sheet, closes it with Escape, and restores focus', async () => {
    respondWithReadyFoundation();

    renderApp();
    await screen.findByRole('heading', { name: 'Synthèse' });

    const moreButton = screen.getByRole('button', { name: 'Plus' });
    fireEvent.click(moreButton);
    const sheet = screen.getByRole('dialog', { name: 'Navigation complémentaire' });
    expect(within(sheet).getByRole('link', { name: 'Objectifs' })).toBeTruthy();
    const closeButton = within(sheet).getByRole('button', { name: 'Fermer' });
    await waitFor(() => expect(document.activeElement).toBe(closeButton));
    expect(document.querySelector('.app-shell')?.hasAttribute('inert')).toBe(true);
    expect(document.body.style.overflow).toBe('hidden');

    const lastLink = within(sheet).getByRole('link', { name: 'Paramètres' });
    lastLink.focus();
    fireEvent.keyDown(document, { key: 'Tab' });
    expect(document.activeElement).toBe(closeButton);

    fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByRole('dialog', { name: 'Navigation complémentaire' })).toBeNull();
    expect(document.querySelector('.app-shell')?.hasAttribute('inert')).toBe(false);
    expect(document.body.style.overflow).toBe('');
    await waitFor(() => expect(document.activeElement).toBe(moreButton));
  });

  it('gives explicit translated feedback for foundation search and add actions', async () => {
    respondWithReadyFoundation();

    renderApp();
    await screen.findByText('Données anciennes');

    fireEvent.click(screen.getByRole('button', { name: 'Rechercher' }));
    const searchDialog = screen.getByRole('dialog', { name: 'Rechercher dans Cadran' });
    expect(within(searchDialog).getByRole('status').textContent).toContain(
      'Recherche bientôt disponible',
    );
    fireEvent.keyDown(document, { key: 'Escape' });

    fireEvent.click(screen.getAllByRole('button', { name: 'Ajouter' })[0]);
    const addDialog = screen.getByRole('dialog', { name: 'Ajouter une donnée' });
    expect(within(addDialog).getByRole('status').textContent).toContain('Ajout bientôt disponible');
  });

  it('shows a recoverable error inside the shell for a network failure', async () => {
    vi.stubGlobal(
      'fetch',
      vi.fn(async () => Promise.reject(new Error('offline'))),
    );

    renderApp();

    expect((await screen.findByRole('alert')).textContent).toContain(
      'L’API est actuellement indisponible.',
    );
    expect(screen.getByRole('button', { name: 'Réessayer' })).toBeTruthy();
    expect(screen.getByRole('navigation', { name: 'Navigation principale' })).toBeTruthy();
  });
});
