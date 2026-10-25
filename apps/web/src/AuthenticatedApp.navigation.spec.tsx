import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { act, cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AuthenticatedApp } from './AuthenticatedApp';

const gates = vi.hoisted(() => {
  function createGate() {
    let open: () => void = () => undefined;
    const opened = new Promise<void>((resolve) => {
      open = resolve;
    });
    return { open, opened };
  }
  return {
    accounts: createGate(),
    goals: createGate(),
    portfolios: createGate(),
    budget: createGate(),
    transactions: createGate(),
  };
});

// Each page chunk stays "in flight" until its test opens the gate.
vi.mock('@/features/goals/GoalsPage', async () => {
  await gates.goals.opened;
  return { GoalsPage: () => <p>Contenu des objectifs</p> };
});
vi.mock('@/features/budget/monthly-budget/MonthlyBudgetPage', async () => {
  await gates.budget.opened;
  return { MonthlyBudgetPage: () => <p>Contenu du budget</p> };
});
vi.mock('@/features/transactions/TransactionsPage', async () => {
  await gates.transactions.opened;
  return { TransactionsPage: () => <p>Contenu des transactions</p> };
});
vi.mock('@/features/accounts/AccountsPage', async () => {
  await gates.accounts.opened;
  return { AccountsPage: () => <p>Contenu des comptes</p> };
});
// A chunk that cannot be fetched, as after an upgrade removed it from the server.
vi.mock('@/features/recurrences/RecurrencesPage', () => {
  throw new Error('Failed to fetch dynamically imported module');
});
vi.mock('@/features/portfolio/portfolios/PortfoliosPage', async () => {
  await gates.portfolios.opened;
  return { PortfoliosPage: () => <p>Contenu des portefeuilles</p> };
});
vi.mock('@/features/dashboard/DashboardPage', () => ({
  DashboardPage: () => <p>Contenu de la synthèse</p>,
}));
vi.mock('@/features/dashboard/net-worth/useDashboardHeader', () => ({
  useDashboardHeader: () => ({}),
}));
vi.mock('@/features/auth/useWorkspaceTimeZone', () => ({
  useWorkspaceTimeZone: () => 'Europe/Paris',
}));

function renderApp() {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return render(
    <QueryClientProvider client={queryClient}>
      <AuthenticatedApp />
    </QueryClientProvider>,
  );
}

describe('AuthenticatedApp lazy navigation', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'fetch',
      vi.fn(
        async () =>
          new Response(JSON.stringify({ version: '1.0.0', apiVersion: 'v1' }), {
            status: 200,
            headers: { 'Content-Type': 'application/json' },
          }),
      ),
    );
  });

  afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
    window.history.replaceState({}, '', '/');
  });

  it('keeps the current page on screen while the next page chunk is still loading', async () => {
    window.history.replaceState({}, '', '/');
    renderApp();
    await screen.findByText('Contenu de la synthèse');

    fireEvent.click(
      within(screen.getByRole('navigation', { name: 'Navigation principale' })).getByRole('link', {
        name: 'Objectifs',
      }),
    );

    // The chunk is not resolved: no fallback, the dashboard has not been replaced.
    expect(screen.getByText('Contenu de la synthèse')).toBeTruthy();
    expect(screen.queryByText('Chargement de la page…')).toBeNull();

    await act(async () => {
      gates.goals.open();
    });

    await screen.findByText('Contenu des objectifs');
    expect(screen.queryByText('Contenu de la synthèse')).toBeNull();
    expect(window.location.pathname).toBe('/goals');
  });

  it('keeps the current page while a back or forward navigation loads its chunk', async () => {
    window.history.replaceState({}, '', '/');
    renderApp();
    await screen.findByText('Contenu de la synthèse');

    window.history.pushState({}, '', '/transactions');
    await act(async () => {
      window.dispatchEvent(new PopStateEvent('popstate'));
    });

    expect(screen.getByText('Contenu de la synthèse')).toBeTruthy();
    expect(screen.queryByText('Chargement de la page…')).toBeNull();

    await act(async () => {
      gates.transactions.open();
    });

    await screen.findByText('Contenu des transactions');
    const sidebar = screen.getByRole('navigation', { name: 'Navigation principale' });
    const current = () => within(sidebar).getByRole('link', { current: 'page' }).textContent;
    expect(current()).toBe('Transactions');

    fireEvent.click(within(sidebar).getByRole('link', { name: 'Objectifs' }));
    await act(async () => {
      gates.goals.open();
    });
    await screen.findByText('Contenu des objectifs');
    expect(current()).toBe('Objectifs');

    window.history.back();
    await waitFor(() => expect(current()).toBe('Transactions'));
    window.history.forward();
    await waitFor(() => expect(current()).toBe('Objectifs'));
  });

  it('shows the shell with the real route title while an initial page chunk is pending', async () => {
    window.history.replaceState({}, '', '/budget/2020/03');
    renderApp();

    expect(await screen.findByRole('heading', { level: 2, name: 'Budget' })).toBeTruthy();
    expect(screen.getByRole('navigation', { name: 'Navigation principale' })).toBeTruthy();
    expect(screen.getByRole('status').textContent).toBe('Chargement de la page…');

    await act(async () => {
      gates.budget.open();
    });

    await screen.findByText('Contenu du budget');
    expect(screen.queryByRole('status')).toBeNull();
  });

  function sidebarLink(name: string) {
    return within(screen.getByRole('navigation', { name: 'Navigation principale' })).getByRole(
      'link',
      { name },
    );
  }

  it('flags the content region busy only while the next page chunk is loading', async () => {
    window.history.replaceState({}, '', '/');
    renderApp();
    await screen.findByText('Contenu de la synthèse');
    const main = screen.getByRole('main');
    expect(main.getAttribute('aria-busy')).toBeNull();

    fireEvent.click(sidebarLink('Investissements'));

    await waitFor(() => expect(main.getAttribute('aria-busy')).toBe('true'));
    await act(async () => {
      gates.portfolios.open();
    });
    await screen.findByText('Contenu des portefeuilles');
    expect(main.getAttribute('aria-busy')).toBeNull();
  });

  it('ends on the page of the last click when the user goes back before the chunk arrives', async () => {
    window.history.replaceState({}, '', '/');
    renderApp();
    await screen.findByText('Contenu de la synthèse');

    fireEvent.click(sidebarLink('Comptes'));
    fireEvent.click(sidebarLink('Synthèse'));
    expect(window.location.pathname).toBe('/');

    await act(async () => {
      gates.accounts.open();
    });

    expect(screen.getByText('Contenu de la synthèse')).toBeTruthy();
    expect(screen.queryByText('Contenu des comptes')).toBeNull();
    expect(window.location.pathname).toBe('/');
  });

  describe('chunk load failure', () => {
    beforeEach(() => {
      // React logs every error it hands to a boundary.
      vi.spyOn(console, 'error').mockImplementation(() => undefined);
    });

    it('keeps the shell and offers a reload when the initial page chunk cannot load', async () => {
      window.history.replaceState({}, '', '/transactions/recurrences');
      renderApp();

      expect((await screen.findByRole('alert')).textContent).toContain(
        'La page n’a pas pu être chargée',
      );
      expect(screen.getByRole('button', { name: 'Recharger la page' })).toBeTruthy();
      expect(screen.getByRole('heading', { level: 2, name: 'Récurrences' })).toBeTruthy();
      expect(screen.getByRole('navigation', { name: 'Navigation principale' })).toBeTruthy();
    });

    it('clears the error when the user navigates to another page', async () => {
      window.history.replaceState({}, '', '/transactions/recurrences');
      renderApp();
      await screen.findByRole('alert');

      fireEvent.click(sidebarLink('Synthèse'));

      expect(await screen.findByText('Contenu de la synthèse')).toBeTruthy();
      expect(screen.queryByRole('alert')).toBeNull();
    });

    it('shows the error in place of the page when a navigation lands on a missing chunk', async () => {
      window.history.replaceState({}, '', '/');
      renderApp();
      await screen.findByText('Contenu de la synthèse');

      window.history.pushState({}, '', '/transactions/recurrences');
      await act(async () => {
        window.dispatchEvent(new PopStateEvent('popstate'));
      });

      expect(await screen.findByRole('alert')).toBeTruthy();
      expect(screen.getByRole('navigation', { name: 'Navigation principale' })).toBeTruthy();
    });
  });
});
