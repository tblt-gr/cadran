import type { CategorizationRule } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { CategorizationRulesPage } from './CategorizationRulesPage';

const api = vi.hoisted(() => ({
  applyCategorizationRules: vi.fn(),
  archiveCategorizationRule: vi.fn(),
  createCategorizationRule: vi.fn(),
  listAccounts: vi.fn(),
  listCategorizationRules: vi.fn(),
  previewCategorizationRules: vi.fn(),
  updateCategorizationRule: vi.fn(),
}));
vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const rule: CategorizationRule = {
  id: '00000000-0000-7000-8000-000000000001',
  label: '<img src=x onerror=alert(1)>',
  priority: 1,
  accountScope: [],
  conditions: { rawLabel: { operator: 'CONTAINS', value: 'CARREFOUR' } },
  targetCategoryId: '00000000-0000-7000-8000-000000000002',
  targetCategoryLabel: 'Courses',
  targetAxes: [],
  targetCounterparty: null,
  effectiveFrom: '2026-01-01',
  effectiveTo: null,
  active: true,
  deactivatedReason: null,
  appliedCount: 0,
  version: 1,
  createdAt: '2026-01-01T00:00:00+00:00',
  updatedAt: '2026-01-01T00:00:00+00:00',
  archivedAt: null,
};
function success<T>(data: T) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status: 200 }) });
}
function renderPage() {
  return render(
    <QueryClientProvider
      client={
        new QueryClient({
          defaultOptions: { queries: { retry: false }, mutations: { retry: false } },
        })
      }
    >
      <CategorizationRulesPage />
    </QueryClientProvider>,
  );
}

describe('CategorizationRulesPage', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });
  it('covers the empty state and opens the shared editor', async () => {
    api.listAccounts.mockImplementation(() => success({ items: [] }));
    api.listCategorizationRules.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 100, total: 0 }),
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Créer la première règle' }));
    expect(screen.getByRole('dialog', { name: 'Nouvelle règle' })).toBeTruthy();
    expect(
      screen.queryByText('Aucune transaction n’a encore été modifiée.', { exact: false }),
    ).toBeNull();
  });
  it('renders stored labels as text and only enables application after a preview', async () => {
    api.listAccounts.mockImplementation(() => success({ items: [] }));
    api.listCategorizationRules.mockImplementation(() =>
      success({ items: [rule], page: 1, perPage: 100, total: 1 }),
    );
    api.previewCategorizationRules.mockImplementation(() =>
      success({
        previewToken: 'token',
        matched: 0,
        wouldChange: 0,
        skippedManual: 0,
        conflicts: [],
        samples: [],
        skipped: [],
        deactivatedRuleIds: [],
      }),
    );
    renderPage();
    expect(await screen.findByText(rule.label)).toBeTruthy();
    expect(document.querySelector('img')).toBeNull();
    fireEvent.click(screen.getByRole('button', { name: 'Prévisualiser l’historique' }));
    expect(screen.queryByRole('button', { name: 'Appliquer cette prévisualisation' })).toBeNull();
    fireEvent.click(screen.getAllByRole('button', { name: 'Prévisualiser l’historique' }).at(-1)!);
    await waitFor(() => expect(api.previewCategorizationRules).toHaveBeenCalledOnce());
    expect(
      await screen.findByRole('button', { name: 'Appliquer cette prévisualisation' }),
    ).toBeTruthy();
  });
  it('requires a fresh preview when the apply token becomes stale', async () => {
    api.listAccounts.mockImplementation(() => success({ items: [] }));
    api.listCategorizationRules.mockImplementation(() =>
      success({ items: [rule], page: 1, perPage: 100, total: 1 }),
    );
    api.previewCategorizationRules.mockImplementation(() =>
      success({
        previewToken: 'token',
        matched: 1,
        wouldChange: 1,
        skippedManual: 0,
        conflicts: [],
        samples: [],
        skipped: [],
        deactivatedRuleIds: [],
      }),
    );
    api.applyCategorizationRules.mockImplementation(() =>
      Promise.resolve({
        error: { type: '/problems/rules.preview_stale' },
        response: new Response('', { status: 409 }),
      }),
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Prévisualiser l’historique' }));
    fireEvent.click(screen.getAllByRole('button', { name: 'Prévisualiser l’historique' }).at(-1)!);
    const apply = await screen.findByRole('button', { name: 'Appliquer cette prévisualisation' });
    fireEvent.click(apply);
    expect(
      await screen.findByText('Les données ont changé depuis la prévisualisation.', {
        exact: false,
      }),
    ).toBeTruthy();
    expect(screen.queryByRole('button', { name: 'Appliquer cette prévisualisation' })).toBeNull();
  });
});
