import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { LifeInsuranceOverview } from './LifeInsuranceOverview';
import { summaryFixture } from './summaryFixture';
const api = vi.hoisted(() => ({ readLifeInsuranceSummary: vi.fn() }));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
const ok = (data: unknown) => ({ data, response: new Response('{}', { status: 200 }) });
function mount(client = new QueryClient({ defaultOptions: { queries: { retry: false } } })) {
  render(
    <QueryClientProvider client={client}>
      <LifeInsuranceOverview accountId="account-1" asOf="2026-03-10" />
    </QueryClientProvider>,
  );
  return client;
}
beforeEach(() => {
  vi.resetAllMocks();
  api.readLifeInsuranceSummary.mockResolvedValue(ok(summaryFixture()));
});
afterEach(cleanup);
describe('LifeInsuranceOverview', () => {
  it('renders only the contract figures in the shared overview', async () => {
    mount();
    await waitFor(() =>
      expect(screen.queryByTestId('life-insurance-overview-skeleton')).toBeNull(),
    );
    expect(screen.getByRole('heading', { name: 'Valeur du contrat' })).toBeTruthy();
    expect(screen.getAllByText(/9\s293,00/).length).toBeGreaterThan(0);
    for (const heading of [
      'Répartition',
      'Supports du contrat',
      'Qualité des données',
      'Montants cumulés enregistrés',
    ])
      expect(screen.queryByRole('heading', { name: heading })).toBeNull();
    expect(api.readLifeInsuranceSummary).toHaveBeenCalledWith(
      expect.objectContaining({
        path: { accountId: 'account-1' },
        query: { asOf: '2026-03-10' },
        signal: expect.any(AbortSignal),
      }),
    );
  });
  it('keeps titles, headers and disabled explanation controls during loading', () => {
    api.readLifeInsuranceSummary.mockReturnValue(new Promise(() => {}));
    mount();
    expect(screen.getByTestId('life-insurance-overview-skeleton')).toBeTruthy();
    expect(screen.queryByRole('heading', { name: 'Répartition' })).toBeNull();
    expect(screen.getByRole('heading', { name: 'Valeur du contrat' })).toBeTruthy();
    expect(
      (screen.getByRole('button', { name: 'Expliquer : Valeur du contrat' }) as HTMLButtonElement)
        .disabled,
    ).toBe(true);
    expect(screen.getAllByRole('status')).toHaveLength(1);
  });
  it('opens sourced explanations and closes by Escape, restoring focus', async () => {
    mount();
    await waitFor(() =>
      expect(screen.queryByTestId('life-insurance-overview-skeleton')).toBeNull(),
    );
    const button = screen.getByRole('button', { name: 'Expliquer : Versements cumulés' });
    await waitFor(() => expect(button.hasAttribute('disabled')).toBe(false));
    button.focus();
    fireEvent.click(button);
    const dialog = screen.getByRole('dialog', { name: 'Versements cumulés' });
    expect(
      within(dialog)
        .getByRole('link', { name: /Opération du/ })
        .getAttribute('href'),
    ).toContain('operation=premium-1');
    expect(within(dialog).getByText(/24 autres/)).toBeTruthy();
    fireEvent.keyDown(dialog, { key: 'Escape' });
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(document.activeElement).toBe(button);
    fireEvent.click(screen.getByRole('button', { name: "Expliquer : Gain depuis l'ouverture" }));
    expect(within(screen.getByRole('dialog')).getByTestId('formula').textContent).toContain(
      '250,00',
    );
  });
  it('shows no valuation explicitly', async () => {
    const status = 'NO_VALUATION' as const;
    const data = summaryFixture();
    data.allocation = { status, items: [] };
    data.supports = [];
    if (status === 'NO_VALUATION') data.valuation = null;
    api.readLifeInsuranceSummary.mockResolvedValue(ok(data));
    mount();
    expect(
      (
        await screen.findAllByText(
          status === 'NO_VALUATION'
            ? 'Aucune valorisation'
            : 'Répartition indisponible : la dernière valorisation ne détaille pas les supports.',
        )
      ).length,
    ).toBeGreaterThan(0);
    expect(screen.queryByRole('img', { name: 'Répartition du contrat' })).toBeNull();
  });
  it('keeps a null gain non calculable with its quality and reasons', async () => {
    const summary = summaryFixture();
    summary.gainSinceOpening = {
      ...summary.gainSinceOpening,
      value: null,
      quality: 'NOT_CALCULABLE',
      reasons: [{ code: 'NO_END_VALUATION', dates: [] }],
    };
    api.readLifeInsuranceSummary.mockResolvedValue(ok(summary));
    mount();
    await waitFor(() =>
      expect(screen.queryByTestId('life-insurance-overview-skeleton')).toBeNull(),
    );
    const gain = screen
      .getByRole('button', { name: "Expliquer : Gain depuis l'ouverture" })
      .closest('article')!;
    expect(within(gain).getByText('Non calculable')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: "Expliquer : Gain depuis l'ouverture" }));
    expect(
      within(screen.getByRole('dialog')).getByText('Aucune valorisation complète dans la période.'),
    ).toBeTruthy();
  });
  it('offers the contract setup link without a contract record', async () => {
    api.readLifeInsuranceSummary.mockResolvedValue(ok({ ...summaryFixture(), contract: null }));
    mount();
    expect(await screen.findByRole('link', { name: 'Renseigner le contrat' })).toBeTruthy();
  });
  it('shows stale age and observed statement discrepancy', async () => {
    const data = summaryFixture();
    data.valuation = {
      ...data.valuation!,
      stale: true,
      ageDays: 32,
      observedTotalDifference: '2',
      flags: ['OBSERVED_TOTAL_DIFFERENCE'],
    };
    api.readLifeInsuranceSummary.mockResolvedValue(ok(data));
    mount();
    expect(await screen.findByText('Valorisation ancienne (32 jours)')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Expliquer : Valeur du contrat' }));
    expect(within(screen.getByRole('dialog')).getByText(/Écart au relevé/)).toBeTruthy();
  });
  it('shows access errors and retries', async () => {
    api.readLifeInsuranceSummary.mockResolvedValue({
      response: new Response('{}', { status: 404 }),
    });
    mount();
    expect(await screen.findByRole('alert')).toHaveProperty(
      'textContent',
      expect.stringContaining('introuvable'),
    );
    api.readLifeInsuranceSummary.mockResolvedValue(ok(summaryFixture()));
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    await waitFor(() => expect(screen.queryByRole('alert')).toBeNull());
  });
  it('retains cached values after a failed refresh', async () => {
    const client = mount();
    await screen.findByText(/9\s293,00/);
    api.readLifeInsuranceSummary.mockRejectedValue(new Error('offline'));
    await client.invalidateQueries({ queryKey: ['life-insurance-summary'] });
    expect(await screen.findByRole('alert')).toHaveProperty(
      'textContent',
      expect.stringContaining('dernières données'),
    );
    expect(screen.getAllByText(/9\s293,00/).length).toBeGreaterThan(0);
  });
});
