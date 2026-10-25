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

  it('keeps the columns and incomplete-month controls disabled until preferences load', async () => {
    renderToolbar();
    expect((screen.getByRole('button', { name: 'Colonnes' }) as HTMLButtonElement).disabled).toBe(
      true,
    );
    expect(
      (screen.getByRole('button', { name: /mois incomplets/i }) as HTMLButtonElement).disabled,
    ).toBe(true);
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

  it('toggles the incomplete months with the stored version', async () => {
    renderToolbar();
    await waitFor(() =>
      expect(
        (screen.getByRole('button', { name: /mois incomplets/i }) as HTMLButtonElement).disabled,
      ).toBe(false),
    );
    fireEvent.click(screen.getByRole('button', { name: /mois incomplets/i }));
    await waitFor(() =>
      expect(api.saveAnnualReportPreferences).toHaveBeenCalledWith(
        expect.objectContaining({
          body: { columns: annualPreferences.columns, incompleteMonths: 'include', version: 3 },
        }),
      ),
    );
  });

  it('tells the user when the preferences changed elsewhere (409)', async () => {
    api.saveAnnualReportPreferences.mockResolvedValue({
      data: undefined,
      response: { ok: false, status: 409 },
    });
    renderToolbar();
    await waitFor(() =>
      expect(
        (screen.getByRole('button', { name: /mois incomplets/i }) as HTMLButtonElement).disabled,
      ).toBe(false),
    );
    fireEvent.click(screen.getByRole('button', { name: /mois incomplets/i }));
    expect(
      await screen.findByText('Les préférences ont changé ailleurs : elles ont été rechargées.'),
    ).toBeTruthy();
  });
});
