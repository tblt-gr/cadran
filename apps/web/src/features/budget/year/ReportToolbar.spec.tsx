import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { annualPreferences } from '@/features/reports/annual/annualFixtures';
import { ReportToolbar } from './ReportToolbar';

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

function renderToolbar(props: Partial<React.ComponentProps<typeof ReportToolbar>> = {}) {
  const onViewChange = vi.fn();
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  render(
    <QueryClientProvider client={client}>
      <ReportToolbar multiYearAvailable onViewChange={onViewChange} view="annual" {...props} />
    </QueryClientProvider>,
  );
  return { onViewChange };
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

describe('ReportToolbar', () => {
  it('reports the chosen view and marks the current one as pressed', () => {
    const { onViewChange } = renderToolbar();
    expect(screen.getByRole('button', { name: 'Annuel' }).getAttribute('aria-pressed')).toBe(
      'true',
    );
    fireEvent.click(screen.getByRole('button', { name: 'Pluriannuel' }));
    expect(onViewChange).toHaveBeenCalledWith('multi');
  });

  it('disables the multi-year option with its reason when only one year has data', () => {
    renderToolbar({ multiYearAvailable: false });
    const multi = screen.getByRole('button', { name: 'Pluriannuel' }) as HTMLButtonElement;
    expect(multi.disabled).toBe(true);
    expect(multi.getAttribute('title')).toBe('Nécessite au moins deux années de données');
  });

  it('keeps the columns control disabled until preferences load', async () => {
    renderToolbar();
    expect((screen.getByRole('button', { name: 'Colonnes' }) as HTMLButtonElement).disabled).toBe(
      true,
    );
    await waitFor(() =>
      expect((screen.getByRole('button', { name: 'Colonnes' }) as HTMLButtonElement).disabled).toBe(
        false,
      ),
    );
  });

  it('opens the column picker once preferences are known', async () => {
    renderToolbar();
    await waitFor(() =>
      expect((screen.getByRole('button', { name: 'Colonnes' }) as HTMLButtonElement).disabled).toBe(
        false,
      ),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Colonnes' }));
    expect(await screen.findByRole('dialog')).toBeTruthy();
  });

  it('offers no incomplete-month switch: the running month always counts', async () => {
    renderToolbar();
    await waitFor(() =>
      expect((screen.getByRole('button', { name: 'Colonnes' }) as HTMLButtonElement).disabled).toBe(
        false,
      ),
    );
    expect(screen.queryByRole('button', { name: /mois incomplets/i })).toBeNull();
  });

  it('tells the user when the preferences changed elsewhere (409) while saving columns', async () => {
    api.saveAnnualReportPreferences.mockResolvedValue({
      data: undefined,
      response: { ok: false, status: 409 },
    });
    renderToolbar();
    await waitFor(() =>
      expect((screen.getByRole('button', { name: 'Colonnes' }) as HTMLButtonElement).disabled).toBe(
        false,
      ),
    );
    fireEvent.click(screen.getByRole('button', { name: 'Colonnes' }));
    fireEvent.click(await screen.findByRole('button', { name: 'Enregistrer' }));
    expect(
      await screen.findByText('Les préférences ont changé ailleurs : elles ont été rechargées.'),
    ).toBeTruthy();
  });
});
