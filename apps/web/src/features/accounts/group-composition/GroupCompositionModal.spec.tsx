import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { createRef } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { GroupCompositionModal } from './GroupCompositionModal';
import { account, noShare, PARENT_ID, subgroup, workedExample } from './compositionFixtures';

const api = vi.hoisted(() => ({ readAccountGroupComposition: vi.fn() }));
const box = vi.hoisted(() => ({ size: { height: 0, width: 0 } }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));
vi.mock('@/hooks/use-box-size', () => ({
  useBoxSize: () => ({ ref: { current: null }, size: box.size }),
}));

function success<T>(data: T) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}

function failure(status: number) {
  return Promise.resolve({
    data: undefined,
    error: { type: '/problems/x', title: 'x', status },
    response: new Response('{}', { status }),
  });
}

function renderModal(close = vi.fn(), returnFocus = createRef<HTMLButtonElement>()) {
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={queryClient}>
      <GroupCompositionModal
        asOf="2026-10-25"
        close={close}
        groupId={PARENT_ID}
        label="Investissement"
        returnFocus={returnFocus}
      />
    </QueryClientProvider>,
  );
  return { close };
}

afterEach(() => {
  cleanup();
  vi.clearAllMocks();
  box.size = { height: 0, width: 0 };
});

describe('GroupCompositionModal', () => {
  it('shows the title and date at once and blocks only values while loading', () => {
    api.readAccountGroupComposition.mockReturnValue(new Promise(() => undefined));
    renderModal();

    expect(screen.getByRole('dialog', { name: 'Répartition : Investissement' })).toBeTruthy();
    expect(screen.getByText('25/10/2026')).toBeTruthy();
    expect(screen.getByRole('tab', { name: 'Par sous-groupe' })).toBeTruthy();
    expect(screen.getByRole('tab', { name: 'Par compte' })).toBeTruthy();
    expect(
      screen.getAllByRole('list', { name: 'Répartition par sous-groupe' }).length,
    ).toBeGreaterThan(0);
    expect(document.querySelectorAll('[data-skeleton]').length).toBeGreaterThan(0);
    expect(screen.getAllByRole('status')[0]!.textContent).toBe('Chargement de la répartition...');
  });

  it('offers sub-group and account tabs, sub-group first, with the same totals', async () => {
    api.readAccountGroupComposition.mockReturnValue(success(workedExample()));
    renderModal();

    await screen.findByText('Bourse');
    const bySubgroup = screen.getByRole('tab', { name: 'Par sous-groupe' });
    expect(bySubgroup.getAttribute('aria-selected')).toBe('true');
    const panel = screen.getByRole('tabpanel', { name: 'Par sous-groupe' });
    const rows = within(panel).getAllByRole('listitem');
    expect(within(rows[0]!).getByText('Bourse')).toBeTruthy();
    expect(within(rows[0]!).getByText(/900,00/)).toBeTruthy();
    expect(within(rows[0]!).getByText(/60,00/)).toBeTruthy();
    expect(within(panel).getByText('Comptes directs')).toBeTruthy();
    expect(within(panel).getByText(/6,67/)).toBeTruthy();

    fireEvent.click(screen.getByRole('tab', { name: 'Par compte' }));
    const accounts = screen.getByRole('tabpanel', { name: 'Par compte' });
    expect(within(accounts).getByText('PEA')).toBeTruthy();
    expect(within(accounts).getByText('SCPI')).toBeTruthy();
    expect(within(accounts).getByText('Compte espèces')).toBeTruthy();
  });

  it('shows the account view alone, without tabs, when the group has no sub-group', async () => {
    api.readAccountGroupComposition.mockReturnValue(
      success(workedExample({ bySubgroup: [], byAccount: [account({ id: 'a1', label: 'PEA' })] })),
    );
    renderModal();

    await screen.findByText('PEA');
    expect(screen.queryByRole('tab')).toBeNull();
    expect(screen.getByRole('list', { name: 'Répartition par compte' })).toBeTruthy();
  });

  it('draws the donut with the server share when the chart is available', async () => {
    box.size = { height: 288, width: 400 };
    api.readAccountGroupComposition.mockReturnValue(success(workedExample()));
    renderModal();

    await screen.findByText('Bourse');
    expect(screen.getByRole('img', { name: 'Répartition de Investissement' })).toBeTruthy();
  });

  it('replaces the chart by the reason and keeps the legend with dashes', async () => {
    api.readAccountGroupComposition.mockReturnValue(
      success(
        workedExample({
          total: null,
          totalReason: 'MISSING_VALUATION',
          chartReason: 'MISSING_VALUATION',
          bySubgroup: [
            subgroup({
              id: 'g',
              label: 'Bourse',
              value: null,
              share: noShare('MISSING_VALUATION'),
            }),
          ],
        }),
      ),
    );
    renderModal();

    await screen.findByText('Bourse');
    const panel = screen.getByRole('tabpanel', { name: 'Par sous-groupe' });
    expect(within(panel).getByText(/graphique n'est pas affiché/i)).toBeTruthy();
    expect(within(panel).queryByRole('img')).toBeNull();
    expect(within(panel).getByText('Bourse')).toBeTruthy();
    expect(within(panel).getAllByText('-').length).toBeGreaterThanOrEqual(2);
  });

  it('explains a negative component while still showing every share', async () => {
    api.readAccountGroupComposition.mockReturnValue(
      success(workedExample({ chartReason: 'NEGATIVE_COMPONENT' })),
    );
    renderModal();

    await screen.findByText('Bourse');
    const panel = screen.getByRole('tabpanel', { name: 'Par sous-groupe' });
    expect(within(panel).getByText(/composante négative/i)).toBeTruthy();
    expect(within(panel).getByText(/60,00/)).toBeTruthy();
  });

  it('shows an error inside the modal and retries', async () => {
    api.readAccountGroupComposition
      .mockReturnValueOnce(failure(500))
      .mockReturnValueOnce(success(workedExample()));
    renderModal();

    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Répartition indisponible');
    fireEvent.click(within(alert).getByRole('button', { name: 'Réessayer' }));

    await screen.findByText('Bourse');
    expect(api.readAccountGroupComposition).toHaveBeenCalledTimes(2);
    expect(api.readAccountGroupComposition.mock.calls[0]![0]).toMatchObject({
      path: { id: PARENT_ID },
      query: { asOf: '2026-10-25' },
    });
  });

  it('closes on Escape', async () => {
    api.readAccountGroupComposition.mockReturnValue(success(workedExample()));
    const { close } = renderModal();
    await screen.findByText('Bourse');

    fireEvent.keyDown(document, { key: 'Escape' });
    await waitFor(() => expect(close).toHaveBeenCalled());
  });
});
