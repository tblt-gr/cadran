import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { annualPreferences } from '@/features/reports/annual/annualFixtures';
import { ReportPreferencesControls } from './ReportPreferencesControls';

const api = vi.hoisted(() => ({
  readAnnualReportPreferences: vi.fn(),
  saveAnnualReportPreferences: vi.fn(),
  listCategories: vi.fn(),
  listAccountGroups: vi.fn(),
  listAccounts: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
const ok = <T,>(data: T) => ({ data, response: { ok: true, status: 200 } });
function renderControls() {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <ReportPreferencesControls />
    </QueryClientProvider>,
  );
  return { client };
}
beforeEach(() => {
  api.readAnnualReportPreferences.mockResolvedValue(ok(annualPreferences));
  api.saveAnnualReportPreferences.mockResolvedValue(ok({ ...annualPreferences, version: 4 }));
  api.listCategories.mockResolvedValue(ok({ items: [], total: 0 }));
  api.listAccountGroups.mockResolvedValue(ok({ items: [], total: 0 }));
  api.listAccounts.mockResolvedValue(ok({ items: [], total: 0 }));
});
afterEach(() => {
  cleanup();
  vi.clearAllMocks();
});

describe('report goal mark preference', () => {
  it('keeps the labelled toggle visible and disabled while preferences load', () => {
    api.readAnnualReportPreferences.mockImplementation(() => new Promise(() => undefined));
    renderControls();
    const toggle = screen.getByRole('button', { name: 'Repères d’objectifs' });
    expect(toggle.hasAttribute('disabled')).toBe(true);
    expect(toggle.getAttribute('aria-pressed')).toBe('false');
  });

  it.each([true, false])(
    'preserves an existing saved %s choice and saves only its inverse',
    async (goalMarks) => {
      const original = {
        ...annualPreferences,
        goalMarks,
        columns: ['budgetExpenses', 'cashIncome'],
        incompleteMonths: 'exclude' as const,
      };
      api.readAnnualReportPreferences.mockResolvedValue(ok(original));
      api.saveAnnualReportPreferences.mockResolvedValue(
        ok({ ...original, goalMarks: !goalMarks, version: original.version + 1 }),
      );
      renderControls();
      const toggle = screen.getByRole('button', { name: 'Repères d’objectifs' });
      await waitFor(() => expect(toggle.hasAttribute('disabled')).toBe(false));
      expect(toggle.getAttribute('aria-pressed')).toBe(String(goalMarks));
      fireEvent.click(toggle);
      await waitFor(() =>
        expect(api.saveAnnualReportPreferences).toHaveBeenCalledWith(
          expect.objectContaining({
            body: {
              columns: original.columns,
              incompleteMonths: 'exclude',
              goalMarks: !goalMarks,
              version: original.version,
            },
          }),
        ),
      );
      await waitFor(() => expect(toggle.getAttribute('aria-pressed')).toBe(String(!goalMarks)));
      fireEvent.click(screen.getByRole('button', { name: 'Colonnes' }));
      const box = await screen.findByRole('checkbox', { name: /^Afficher les objectifs/ });
      expect((box as HTMLInputElement).checked).toBe(!goalMarks);
    },
  );

  it('serializes the toolbar toggle and opening a columns draft while saving', async () => {
    api.saveAnnualReportPreferences.mockImplementation(() => new Promise(() => undefined));
    renderControls();
    const toggle = screen.getByRole('button', { name: 'Repères d’objectifs' });
    await waitFor(() => expect(toggle.hasAttribute('disabled')).toBe(false));
    fireEvent.click(toggle);
    await waitFor(() => expect(toggle.hasAttribute('disabled')).toBe(true));
    expect(screen.getByRole('button', { name: 'Colonnes' }).hasAttribute('disabled')).toBe(true);
    fireEvent.click(screen.getByRole('button', { name: 'Colonnes' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(api.saveAnnualReportPreferences).toHaveBeenCalledTimes(1);
  });

  it('retains the saved display on failure and retries the intended choice with current settings', async () => {
    const original = { ...annualPreferences, goalMarks: false };
    api.readAnnualReportPreferences.mockResolvedValue(ok(original));
    api.saveAnnualReportPreferences.mockResolvedValueOnce({ response: { ok: false, status: 500 } });
    api.saveAnnualReportPreferences.mockResolvedValueOnce(
      ok({ ...original, goalMarks: true, version: original.version + 1 }),
    );
    renderControls();
    const toggle = screen.getByRole('button', { name: 'Repères d’objectifs' });
    await waitFor(() => expect(toggle.hasAttribute('disabled')).toBe(false));
    fireEvent.click(toggle);
    expect(await screen.findByRole('alert')).toBeTruthy();
    expect(toggle.getAttribute('aria-pressed')).toBe('false');
    fireEvent.click(screen.getByRole('button', { name: 'Réessayer' }));
    await waitFor(() => expect(toggle.getAttribute('aria-pressed')).toBe('true'));
    expect(api.saveAnnualReportPreferences).toHaveBeenCalledTimes(2);
    expect(api.saveAnnualReportPreferences.mock.calls[1]![0].body).toEqual({
      columns: original.columns,
      goalMarks: true,
      incompleteMonths: original.incompleteMonths,
      version: original.version,
    });
  });

  it('reloads a conflict instead of retrying or overwriting newer columns', async () => {
    api.saveAnnualReportPreferences.mockResolvedValue({ response: { ok: false, status: 409 } });
    renderControls();
    const toggle = screen.getByRole('button', { name: 'Repères d’objectifs' });
    await waitFor(() => expect(toggle.hasAttribute('disabled')).toBe(false));
    api.readAnnualReportPreferences.mockResolvedValue(
      ok({ ...annualPreferences, columns: ['cashIncome'], goalMarks: false, version: 12 }),
    );
    fireEvent.click(toggle);
    expect(
      await screen.findByText('Les préférences ont changé ailleurs : elles ont été rechargées.'),
    ).toBeTruthy();
    await waitFor(() => expect(toggle.getAttribute('aria-pressed')).toBe('false'));
    expect(api.saveAnnualReportPreferences).toHaveBeenCalledTimes(1);
    expect(screen.queryByRole('button', { name: 'Réessayer' })).toBeNull();
  });

  it('synchronizes a columns checkbox save back to the toolbar toggle', async () => {
    api.saveAnnualReportPreferences.mockResolvedValue(
      ok({ ...annualPreferences, goalMarks: false, version: 4 }),
    );
    renderControls();
    const toggle = screen.getByRole('button', { name: 'Repères d’objectifs' });
    await waitFor(() => expect(toggle.hasAttribute('disabled')).toBe(false));
    fireEvent.click(screen.getByRole('button', { name: 'Colonnes' }));
    fireEvent.click(await screen.findByRole('checkbox', { name: /^Afficher les objectifs/ }));
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(toggle.getAttribute('aria-pressed')).toBe('false');
  });

  it('does not invalidate financial reports or evaluations for a display-only toggle', async () => {
    api.saveAnnualReportPreferences.mockResolvedValue(
      ok({ ...annualPreferences, goalMarks: false, version: 4 }),
    );
    const { client } = renderControls();
    const toggle = screen.getByRole('button', { name: 'Repères d’objectifs' });
    await waitFor(() => expect(toggle.hasAttribute('disabled')).toBe(false));
    const invalidate = vi.spyOn(client, 'invalidateQueries');
    fireEvent.click(toggle);
    await waitFor(() => expect(toggle.getAttribute('aria-pressed')).toBe('false'));
    expect(invalidate).not.toHaveBeenCalled();
  });
});
