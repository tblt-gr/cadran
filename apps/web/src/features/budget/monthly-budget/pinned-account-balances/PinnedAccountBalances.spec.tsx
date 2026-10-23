import type { MonthlyRecapPreferences } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { invalidateAccountBalances } from '@/features/accounts/invalidateAccountBalances';
import { PinnedAccountsModal } from './PinnedAccountsModal';
import { PinnedAccountBalances, PinnedAccountBalancesView } from './PinnedAccountBalances';
const api = vi.hoisted(() => ({
  readMonthlyRecap: vi.fn(),
  readMonthlyRecapPreferences: vi.fn(),
  saveMonthlyRecapPreferences: vi.fn(),
  listAccounts: vi.fn(),
  getSession: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
const row = {
  accountId: 'a',
  label: 'Courant',
  assetCode: 'EUR',
  status: 'ARCHIVED' as const,
  closedOn: '2026-03-15',
  archivedAt: '2026-04-01T00:00:00Z',
  value: '0',
  displayValue: '0',
  belowDisplayStep: false,
  requestedOn: '2026-03-31',
  valuedOn: '2026-03-31',
  quality: 'CURRENT' as const,
  source: 'CALCULATED' as const,
  ageDays: 0,
  basis: 'SNAPSHOT_PLUS_MOVEMENTS' as const,
  reason: null,
};
const success = (data: unknown) => ({ data, response: new Response(null, { status: 200 }) });
const prefs: MonthlyRecapPreferences = {
  visibleAccountIds: ['a'],
  visibleAxes: ['FIXED'],
  visibleCategoryIds: ['category'],
  version: 2,
};
afterEach(cleanup);
beforeEach(() => {
  vi.resetAllMocks();
  api.getSession.mockResolvedValue(
    success({ authenticated: true, csrfToken: 'csrf', workspace: { role: 'OWNER' } }),
  );
  api.readMonthlyRecap.mockResolvedValue(success({ selectedAccounts: [row] }));
  api.readMonthlyRecapPreferences.mockResolvedValue(success(prefs));
  api.listAccounts.mockResolvedValue(
    success({
      items: [
        { id: 'a', label: 'Courant', status: 'ARCHIVED', assetCode: 'EUR' },
        ...Array.from({ length: 7 }, (_, i) => ({
          id: `b${i}`,
          label: `Autre ${i}`,
          status: 'ACTIVE',
          assetCode: 'EUR',
        })),
      ],
      total: 8,
    }),
  );
  api.saveMonthlyRecapPreferences.mockImplementation(({ body }) =>
    Promise.resolve(success({ ...body, version: 3 })),
  );
});
function mount() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <PinnedAccountBalances month="2026-03" />
    </QueryClientProvider>,
  );
  return client;
}
it('keeps static chrome and disabled configuration while loading', () => {
  render(<PinnedAccountBalancesView accounts={null} month="2026-03" />);
  expect(screen.getByRole('heading', { name: 'Soldes épinglés' })).toBeTruthy();
  expect(
    (screen.getByRole('button', { name: 'Configurer les comptes épinglés' }) as HTMLButtonElement)
      .disabled,
  ).toBe(true);
  expect(screen.getAllByRole('listitem')).toHaveLength(6);
});
it('shows calculable exact zero using shared display convention with dated provenance and lifecycle', () => {
  render(<PinnedAccountBalancesView accounts={[row]} month="2026-03" />);
  expect(screen.getByText('-')).toBeTruthy();
  expect(screen.getByText('Au jour')).toBeTruthy();
  expect(screen.getByText('Solde au 31 mars 2026')).toBeTruthy();
  expect(screen.queryByText('Non calculable')).toBeNull();
  expect(screen.queryByText('Compte non ouvert à cette date')).toBeNull();
  expect(screen.queryByText('Inférieur à l’échelon d’affichage')).toBeNull();
  expect(screen.getByText(/Archivé/)).toBeTruthy();
  expect(screen.getByRole('link', { name: 'Courant' }).getAttribute('href')).toBe(
    '/accounts/a?month=2026-03',
  );
});
it('shows missing reason without replacing it by zero', () => {
  render(
    <PinnedAccountBalancesView
      accounts={[
        {
          ...row,
          value: null,
          displayValue: null,
          valuedOn: null,
          quality: 'MISSING',
          reason: 'NOT_YET_OPENED',
        },
      ]}
      month="2026-03"
    />,
  );
  expect(screen.getByText('Non calculable')).toBeTruthy();
  expect(screen.getByText('Compte non ouvert à cette date')).toBeTruthy();
  expect(screen.queryByText('-')).toBeNull();
});
it('saves explicit empty and invalidates shared recap, preserving flow preferences', async () => {
  const client = mount();
  const invalidate = vi.spyOn(client, 'invalidateQueries');
  await waitFor(() =>
    expect(
      (screen.getByRole('button', { name: 'Configurer les comptes épinglés' }) as HTMLButtonElement)
        .disabled,
    ).toBe(false),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Configurer les comptes épinglés' }));
  fireEvent.click(await screen.findByRole('checkbox', { name: /Courant/ }));
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
  await waitFor(() =>
    expect(api.saveMonthlyRecapPreferences).toHaveBeenCalledWith(
      expect.objectContaining({ body: { ...prefs, visibleAccountIds: [] } }),
    ),
  );
  await waitFor(() => expect(invalidate).toHaveBeenCalledWith({ queryKey: ['monthly-recap'] }));
});
it('orders accounts with keyboard-operable buttons, limits six and restores null defaults', async () => {
  mount();
  await waitFor(() =>
    expect(
      (screen.getByRole('button', { name: 'Configurer les comptes épinglés' }) as HTMLButtonElement)
        .disabled,
    ).toBe(false),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Configurer les comptes épinglés' }));
  for (let i = 0; i < 5; i++)
    fireEvent.click(await screen.findByRole('checkbox', { name: `Autre ${i} (EUR)` }));
  expect(
    (screen.getByRole('checkbox', { name: 'Autre 5 (EUR)' }) as HTMLInputElement).disabled,
  ).toBe(true);
  fireEvent.click(screen.getByRole('button', { name: 'Monter Autre 0' }));
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
  await waitFor(() =>
    expect(api.saveMonthlyRecapPreferences).toHaveBeenCalledWith(
      expect.objectContaining({
        body: { ...prefs, visibleAccountIds: ['b0', 'a', 'b1', 'b2', 'b3', 'b4'] },
      }),
    ),
  );
  await waitFor(() =>
    expect(
      (screen.getByRole('button', { name: 'Configurer les comptes épinglés' }) as HTMLButtonElement)
        .disabled,
    ).toBe(false),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Configurer les comptes épinglés' }));
  fireEvent.click(await screen.findByRole('button', { name: 'Rétablir les comptes par défaut' }));
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
  await waitFor(() =>
    expect(api.saveMonthlyRecapPreferences).toHaveBeenLastCalledWith(
      expect.objectContaining({ body: expect.objectContaining({ visibleAccountIds: null }) }),
    ),
  );
});
it('retains draft across conflict and reloads only the version and other preferences', async () => {
  mount();
  await waitFor(() =>
    expect(
      (screen.getByRole('button', { name: 'Configurer les comptes épinglés' }) as HTMLButtonElement)
        .disabled,
    ).toBe(false),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Configurer les comptes épinglés' }));
  fireEvent.click(await screen.findByRole('checkbox', { name: 'Autre 0 (EUR)' }));
  api.saveMonthlyRecapPreferences.mockResolvedValueOnce({
    response: new Response(null, { status: 409 }),
  });
  api.readMonthlyRecapPreferences.mockResolvedValue(
    success({ ...prefs, version: 4, visibleAxes: [] }),
  );
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
  await screen.findByText(/Votre sélection est conservée/);
  fireEvent.click(screen.getByRole('button', { name: 'Recharger les préférences' }));
  await waitFor(() =>
    expect(
      (screen.getByRole('button', { name: 'Enregistrer' }) as HTMLButtonElement).disabled,
    ).toBe(false),
  );
  expect(
    (screen.getByRole('checkbox', { name: 'Autre 0 (EUR)' }) as HTMLInputElement).checked,
  ).toBe(true);
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
  await waitFor(() =>
    expect(api.saveMonthlyRecapPreferences).toHaveBeenLastCalledWith(
      expect.objectContaining({
        body: { ...prefs, version: 4, visibleAxes: [], visibleAccountIds: ['a', 'b0'] },
      }),
    ),
  );
});

it('keeps explicit empty selection configurable without substituting accounts', async () => {
  api.readMonthlyRecap.mockResolvedValue(success({ selectedAccounts: [] }));
  api.readMonthlyRecapPreferences.mockResolvedValue(success({ ...prefs, visibleAccountIds: [] }));
  mount();
  await screen.findByText('Aucun compte épinglé. Configurez les comptes à afficher.');
  expect(screen.queryByRole('link', { name: 'Courant' })).toBeNull();
});
it('shows fetch failure and retries the same server read model', async () => {
  api.readMonthlyRecap.mockResolvedValueOnce({ response: new Response(null, { status: 503 }) });
  mount();
  await screen.findByRole('alert');
  fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
  await screen.findByRole('link', { name: 'Courant' });
});
it('keeps configuration disabled without owner permission', async () => {
  api.getSession.mockResolvedValue(success({ workspace: { role: 'VIEWER' } }));
  mount();
  await screen.findByRole('link', { name: 'Courant' });
  expect(
    (screen.getByRole('button', { name: 'Configurer les comptes épinglés' }) as HTMLButtonElement)
      .disabled,
  ).toBe(true);
});
it('refreshes a displayed dated balance after the shared transaction-write invalidation', async () => {
  const client = mount();
  await screen.findByText('-');
  api.readMonthlyRecap.mockResolvedValue(
    success({ selectedAccounts: [{ ...row, value: '-12.34', displayValue: '-12.34' }] }),
  );
  await invalidateAccountBalances(client);
  await screen.findByText('-12,34 €');
});

it.each(['0.000000000000000000000001', '-0.000000000000000000000001'])(
  'distinguishes a nonzero below-step amount %s from true zero',
  (value) => {
    render(
      <PinnedAccountBalancesView
        accounts={[{ ...row, value, displayValue: null, belowDisplayStep: true }]}
        month="2026-03"
      />,
    );
    expect(screen.getByText('Inférieur à l’échelon d’affichage')).toBeTruthy();
    expect(screen.queryByText('-')).toBeNull();
  },
);
it('formats the server display value instead of rounding the exact amount', () => {
  render(
    <PinnedAccountBalancesView
      accounts={[{ ...row, value: '1.234', displayValue: '1.24', belowDisplayStep: false }]}
      month="2026-03"
    />,
  );
  expect(screen.getByText('1,24 €')).toBeTruthy();
  expect(screen.queryByText('1,23 €')).toBeNull();
});

it('labels movement-derived balances as calculated rather than as current manual snapshots', () => {
  render(<PinnedAccountBalancesView accounts={[{ ...row, source: 'MANUAL' }]} month="2026-03" />);
  expect(screen.getByText(/Solde calculé à partir du relevé et des mouvements/)).toBeTruthy();
  expect(screen.queryByText(/Saisie/)).toBeNull();
});
it.each(['MANUAL', 'IMPORT'] as const)('retains %s provenance for actual snapshots', (source) => {
  render(
    <PinnedAccountBalancesView
      accounts={[{ ...row, basis: 'SNAPSHOT', source }]}
      month="2026-03"
    />,
  );
  expect(screen.getByText(source === 'MANUAL' ? /Saisie/ : /Import/)).toBeTruthy();
  expect(screen.queryByText(/Solde calculé à partir du relevé et des mouvements/)).toBeNull();
});
it('freezes known selection and mirrors chooser structure while its accounts query is deferred', async () => {
  let resolveAccounts!: (value: ReturnType<typeof success>) => void;
  api.listAccounts.mockReturnValue(
    new Promise((resolve) => {
      resolveAccounts = resolve;
    }),
  );
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <PinnedAccountsModal
        close={vi.fn()}
        preferences={{ ...prefs, visibleAccountIds: ['a', 'b0'] }}
        selectedAccounts={[row]}
      />
    </QueryClientProvider>,
  );
  const dialog = screen.getByRole('dialog');
  const order = within(dialog).getByRole('group', { name: 'Ordre des comptes' });
  expect(within(order).getByText('Courant')).toBeTruthy();
  for (const button of within(order).getAllByRole('button')) {
    expect(button.matches(':disabled')).toBe(true);
    expect(button.getAttribute('aria-label')).not.toMatch(/undefined/);
  }
  expect(within(order).queryByText('Compte sélectionné')).toBeNull();
  const chooser = within(dialog).getByRole('group', { name: 'Comptes à afficher' });
  const pendingOptions = within(chooser).getAllByRole('checkbox')[0]!.parentElement!.parentElement!;
  expect(
    within(chooser).getByRole('checkbox', { name: 'Courant (EUR) · Archivé' }).matches(':disabled'),
  ).toBe(true);
  expect(within(order).getByRole('button', { name: 'Descendre Courant' })).toBeTruthy();
  expect(order.querySelectorAll('[data-skeleton]')).toHaveLength(1);
  expect(pendingOptions.querySelectorAll('[data-skeleton]')).toHaveLength(5);
  const optionsClass = pendingOptions.className;
  expect(optionsClass).toMatch(/options/);
  const pendingTags = Array.from(pendingOptions.children[0]!.children).map(
    (child) => child.tagName,
  );
  expect(pendingTags).toEqual(['INPUT', 'SPAN']);
  resolveAccounts(
    success({
      items: [
        { id: 'a', label: 'Courant', status: 'ARCHIVED', assetCode: 'EUR' },
        { id: 'b0', label: 'Autre 0', status: 'ACTIVE', assetCode: 'EUR' },
      ],
      total: 2,
    }),
  );
  await within(chooser).findByRole('checkbox', { name: 'Autre 0 (EUR)' });
  const loadedOptions = within(chooser).getAllByRole('checkbox')[0]!.parentElement!.parentElement!;
  expect(loadedOptions.className).toBe(optionsClass);
  expect(pendingTags).toEqual(
    Array.from(loadedOptions.children[0]!.children).map((child) => child.tagName),
  );
  expect(within(order).getByRole('button', { name: 'Monter Autre 0' }).matches(':disabled')).toBe(
    false,
  );
});

it.each([401, 503])(
  'does not claim an empty selection on initial recap failure %s',
  async (status) => {
    api.readMonthlyRecap.mockResolvedValue({ response: new Response(null, { status }) });
    mount();
    await screen.findByRole('alert');
    expect(
      screen.queryByText('Aucun compte épinglé. Configurez les comptes à afficher.'),
    ).toBeNull();
  },
);
it('retains last known selected rows when a background recap refresh fails', async () => {
  const client = mount();
  await screen.findByRole('link', { name: 'Courant' });
  api.readMonthlyRecap.mockResolvedValue({ response: new Response(null, { status: 503 }) });
  await client.invalidateQueries({ queryKey: ['monthly-recap'] });
  await screen.findByRole('alert');
  expect(screen.getByRole('link', { name: 'Courant' })).toBeTruthy();
  expect(screen.queryByText('Aucun compte épinglé. Configurez les comptes à afficher.')).toBeNull();
});
it('preserves the amount wrapper structure while the value is loading', () => {
  const { container, rerender } = render(
    <PinnedAccountBalancesView accounts={null} month="2026-03" />,
  );
  const amount = () => container.querySelector('strong')!;
  const pendingMarkup = amount().querySelector('[data-visual-mask]');
  expect(pendingMarkup).toBeTruthy();
  const tags = Array.from(pendingMarkup!.children).map((child) => [child.tagName, child.className]);
  rerender(<PinnedAccountBalancesView accounts={[row]} month="2026-03" />);
  const loadedMarkup = amount().querySelector('[data-visual-mask]');
  expect(loadedMarkup?.tagName).toBe(pendingMarkup?.tagName);
  expect(
    Array.from(loadedMarkup!.children).map((child) => [child.tagName, child.className]),
  ).toEqual(tags);
});
