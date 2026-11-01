import type { PeriodClosure, PeriodStatus } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { PeriodClosurePanel } from './PeriodClosurePanel';

const api = vi.hoisted(() => ({
  closePeriod: vi.fn(),
  getSession: vi.fn(),
  readPeriodStatus: vi.fn(),
  reopenPeriod: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const closure: PeriodClosure = {
  id: 'c1',
  period: '2026-08',
  closedAt: '2026-09-02T08:00:00Z',
  closedBy: 'u1',
  reopenedAt: null,
  reopenReason: null,
  active: true,
  version: 4,
};

function status(overrides: Partial<PeriodStatus> = {}): PeriodStatus {
  return {
    period: '2026-08',
    closed: false,
    ended: true,
    closure: null,
    blockers: [],
    ...overrides,
  };
}

function ok(data: unknown, code = 200) {
  return { data, response: { ok: true, status: code } };
}

function problem(code: number, type: string, extra: object = {}) {
  return {
    error: { type, title: 't', status: code, detail: 'd', ...extra },
    response: { ok: false, status: code },
  };
}

function session(role: 'OWNER' | null) {
  api.getSession.mockResolvedValue(
    ok({
      provisioned: true,
      authenticated: true,
      setupRequired: false,
      user: { id: 'u1', email: 'a@b.c', displayName: 'A' },
      workspace: role === null ? null : { id: 'w', role, timeZone: 'Europe/Paris' },
    }),
  );
}

function renderPanel(close = vi.fn()) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  return {
    ...render(
      <QueryClientProvider client={queryClient}>
        <PeriodClosurePanel close={close} initialPeriod="2026-08" />
      </QueryClientProvider>,
    ),
    queryClient,
  };
}

beforeEach(() => {
  session('OWNER');
});

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('PeriodClosurePanel', () => {
  it('replaces its one named cold-load skeleton and keeps the status during a refetch', async () => {
    let resolveStatus: ((value: unknown) => void) | undefined;
    api.readPeriodStatus.mockImplementation(
      () =>
        new Promise((resolve) => {
          resolveStatus = resolve;
        }),
    );
    const { queryClient } = renderPanel();

    expect(screen.getByTestId('period-closure-skeleton')).toBeTruthy();
    expect(
      screen.getByTestId('period-closure-skeleton').querySelectorAll('[role="status"]'),
    ).toHaveLength(1);
    resolveStatus?.(ok(status()));
    expect(await screen.findByText('Mois ouvert')).toBeTruthy();
    expect(screen.queryByTestId('period-closure-skeleton')).toBeNull();

    api.readPeriodStatus.mockImplementation(() => new Promise(() => undefined));
    void queryClient.invalidateQueries({ queryKey: ['period-status'] });
    await waitFor(() => expect(api.readPeriodStatus).toHaveBeenCalledTimes(2));
    expect(screen.getByText('Mois ouvert')).toBeTruthy();
    expect(screen.queryByTestId('period-closure-skeleton')).toBeNull();
  });

  it('shows a loading state, then an error with retry', async () => {
    api.readPeriodStatus.mockResolvedValue(problem(500, 'about:blank'));
    renderPanel();

    expect(screen.getByRole('status').textContent).toContain('Chargement');
    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Réessayer' })).toBeTruthy();
  });

  it('shows an open month that can be closed without blockers', async () => {
    api.readPeriodStatus.mockResolvedValue(ok(status()));
    api.closePeriod.mockResolvedValue(ok(closure, 201));
    renderPanel();

    expect(await screen.findByText('Mois ouvert')).toBeTruthy();
    const dialog = screen.getByRole('dialog', { name: 'Clôture du mois' });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Clôturer le mois' }));
    expect(screen.getByRole('dialog', { name: /Clôturer août 2026/ })).toBe(dialog);
    expect(within(dialog).getByText('Aucun point bloquant.')).toBeTruthy();
    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirmer la clôture' }));

    await waitFor(() =>
      expect(api.closePeriod).toHaveBeenCalledWith(
        expect.objectContaining({ path: { period: '2026-08' }, body: { overrides: {} } }),
      ),
    );
  });

  it('returns from the confirmation to the month status in the same dialog', async () => {
    api.readPeriodStatus.mockResolvedValue(ok(status()));
    renderPanel();

    const dialog = await screen.findByRole('dialog', { name: 'Clôture du mois' });
    await within(dialog).findByText('Mois ouvert');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Clôturer le mois' }));
    await waitFor(() =>
      expect(document.activeElement).toBe(within(dialog).getByRole('button', { name: 'Retour' })),
    );
    fireEvent.click(within(dialog).getByRole('button', { name: 'Retour' }));

    expect(screen.getByRole('dialog', { name: 'Clôture du mois' })).toBe(dialog);
    expect(within(dialog).getByText('Mois ouvert')).toBeTruthy();
    await waitFor(() =>
      expect(document.activeElement).toBe(
        within(dialog).getByRole('button', { name: 'Clôturer le mois' }),
      ),
    );
  });

  it('does not offer to close a month that has not ended', async () => {
    api.readPeriodStatus.mockResolvedValue(ok(status({ ended: false })));
    renderPanel();

    expect(await screen.findByText('Ce mois n’est pas terminé.')).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Clôturer le mois' })).toBeNull();
  });

  it('requires one confirmation and one reason per blocking condition', async () => {
    api.readPeriodStatus.mockResolvedValue(
      ok(
        status({
          blockers: [
            { condition: 'UNRECONCILED_ACCOUNT', count: 2 },
            { condition: 'PENDING_TRANSACTIONS', count: 1 },
          ],
        }),
      ),
    );
    api.closePeriod.mockResolvedValue(ok(closure, 201));
    renderPanel();

    fireEvent.click(await screen.findByRole('button', { name: 'Clôturer le mois' }));
    const dialog = await screen.findByRole('dialog', { name: /Clôturer/ });
    const submit = within(dialog).getByRole('button', { name: 'Confirmer la clôture' });
    expect((submit as HTMLButtonElement).disabled).toBe(true);

    expect(
      within(dialog).getByRole('group', {
        name: 'Je confirme clôturer avec 2 compte non rapproché.',
      }),
    ).toBeTruthy();
    expect(
      within(dialog).getByRole('group', {
        name: 'Je confirme clôturer avec 1 transaction en attente.',
      }),
    ).toBeTruthy();
    const boxes = within(dialog).getAllByRole('checkbox');
    expect(boxes).toHaveLength(2);
    fireEvent.click(boxes[0]!);
    fireEvent.change(within(dialog).getByLabelText(/Motif.*compte non rapproché/i), {
      target: { value: 'Relevé attendu' },
    });
    expect((submit as HTMLButtonElement).disabled).toBe(true);
    fireEvent.click(boxes[1]!);
    fireEvent.change(within(dialog).getByLabelText(/Motif.*en attente/i), {
      target: { value: 'Carte différée' },
    });
    expect((submit as HTMLButtonElement).disabled).toBe(false);
    fireEvent.click(submit);

    await waitFor(() =>
      expect(api.closePeriod).toHaveBeenCalledWith(
        expect.objectContaining({
          body: {
            overrides: {
              UNRECONCILED_ACCOUNT: 'Relevé attendu',
              PENDING_TRANSACTIONS: 'Carte différée',
            },
          },
        }),
      ),
    );
  });

  it('labels the missing portfolio valuation and links to the portfolios', async () => {
    api.readPeriodStatus.mockResolvedValue(
      ok(status({ blockers: [{ condition: 'MISSING_PORTFOLIO_VALUATION', count: 2 }] })),
    );
    api.closePeriod.mockResolvedValue(ok(closure, 201));
    const close = vi.fn();
    renderPanel(close);

    fireEvent.click(await screen.findByRole('button', { name: 'Clôturer le mois' }));
    const dialog = await screen.findByRole('dialog', { name: /Clôturer/ });
    expect(
      within(dialog).getByRole('group', {
        name: 'Je confirme clôturer avec 2 portefeuille(s) sans valorisation de fin de mois.',
      }),
    ).toBeTruthy();
    const link = within(dialog).getByRole('link', { name: 'Voir les portefeuilles' });
    expect(link.getAttribute('href')).toBe('/accounts');
    fireEvent.click(link);
    expect(window.location.pathname).toBe('/accounts');
    expect(close).toHaveBeenCalled();

    fireEvent.click(within(dialog).getByRole('checkbox'));
    fireEvent.change(within(dialog).getByLabelText(/Motif.*valorisation/i), {
      target: { value: 'Relevé en attente' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirmer la clôture' }));
    await waitFor(() =>
      expect(api.closePeriod).toHaveBeenCalledWith(
        expect.objectContaining({
          body: { overrides: { MISSING_PORTFOLIO_VALUATION: 'Relevé en attente' } },
        }),
      ),
    );
  });

  it('shows a closed month and reopens it with a reason and the closure version', async () => {
    api.readPeriodStatus.mockResolvedValue(ok(status({ closed: true, closure })));
    api.reopenPeriod.mockResolvedValue(ok({ ...closure, active: false }));
    renderPanel();

    expect(await screen.findByText('Mois clôturé')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Rouvrir le mois' }));
    const dialog = await screen.findByRole('dialog', { name: /Rouvrir/ });
    const submit = within(dialog).getByRole('button', { name: 'Confirmer la réouverture' });
    expect((submit as HTMLButtonElement).disabled).toBe(true);
    fireEvent.change(within(dialog).getByLabelText('Motif de la réouverture'), {
      target: { value: 'Erreur de saisie' },
    });
    fireEvent.click(submit);

    await waitFor(() =>
      expect(api.reopenPeriod).toHaveBeenCalledWith(
        expect.objectContaining({
          path: { period: '2026-08' },
          body: { version: 4, reason: 'Erreur de saisie' },
        }),
      ),
    );
  });

  it('asks to reload when the closure version is stale', async () => {
    api.readPeriodStatus.mockResolvedValue(ok(status({ closed: true, closure })));
    api.reopenPeriod.mockResolvedValue(problem(409, '/problems/stale-version'));
    renderPanel();

    fireEvent.click(await screen.findByRole('button', { name: 'Rouvrir le mois' }));
    const dialog = await screen.findByRole('dialog', { name: /Rouvrir/ });
    fireEvent.change(within(dialog).getByLabelText('Motif de la réouverture'), {
      target: { value: 'x' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirmer la réouverture' }));

    expect((await within(dialog).findByRole('alert')).textContent).toContain('modifié ailleurs');
  });

  it('keeps the actions away from a non-owner and explains why', async () => {
    session(null);
    api.readPeriodStatus.mockResolvedValue(ok(status({ closed: true, closure })));
    renderPanel();

    expect(await screen.findByText('Mois clôturé')).toBeTruthy();
    await waitFor(() => expect(api.getSession).toHaveBeenCalled());
    expect(screen.queryByRole('button', { name: 'Rouvrir le mois' })).toBeNull();
    expect(screen.getByText(/Seul le propriétaire/)).toBeTruthy();
  });

  it('shows a blocked closing returned by the server as an alert', async () => {
    api.readPeriodStatus.mockResolvedValue(ok(status()));
    api.closePeriod.mockResolvedValue(
      problem(409, '/problems/period-closing-blocked', {
        blockers: [{ condition: 'PENDING_TRANSACTIONS', count: 3 }],
      }),
    );
    renderPanel();

    fireEvent.click(await screen.findByRole('button', { name: 'Clôturer le mois' }));
    const dialog = await screen.findByRole('dialog', { name: /Clôturer/ });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Confirmer la clôture' }));

    expect((await within(dialog).findByRole('alert')).textContent).toContain('point bloquant');
  });
});
