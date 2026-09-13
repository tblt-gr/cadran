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
  conditions: {
    text: {
      combinator: 'AND',
      predicates: [
        { source: 'RAW_LABEL', operator: 'CONTAINS', value: 'CARREFOUR', negated: false },
      ],
    },
  },
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
        conflictCount: 0,
        conflicts: [],
        conflictsTruncated: false,
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
      await screen.findByText('Aucun mouvement ne correspond à cette prévisualisation.'),
    ).toBeTruthy();
    expect(
      await screen.findByRole('button', { name: 'Appliquer cette prévisualisation' }),
    ).toBeTruthy();
  });
  it('does not describe an all-manual result as a zero-match preview', async () => {
    api.listAccounts.mockImplementation(() => success({ items: [] }));
    api.listCategorizationRules.mockImplementation(() =>
      success({ items: [rule], page: 1, perPage: 100, total: 1 }),
    );
    api.previewCategorizationRules.mockImplementation(() =>
      success({
        previewToken: 'token',
        matched: 4,
        wouldChange: 0,
        skippedManual: 4,
        conflictCount: 0,
        conflicts: [],
        conflictsTruncated: false,
        samples: [],
        skipped: [
          {
            transactionId: '00000000-0000-7000-8000-000000000010',
            bookedOn: '2026-03-01',
            reason: 'MANUAL_CATEGORIZATION',
          },
        ],
        deactivatedRuleIds: [],
      }),
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Prévisualiser l’historique' }));
    fireEvent.click(screen.getAllByRole('button', { name: 'Prévisualiser l’historique' }).at(-1)!);
    await screen.findByRole('button', { name: 'Appliquer cette prévisualisation' });

    expect(
      screen.queryByText('Aucun mouvement ne correspond à cette prévisualisation.'),
    ).toBeNull();
    expect(screen.getByText('Manuelles préservées').parentElement?.textContent).toContain('4');
  });
  it('renders every returned conflict and the exact conflict count', async () => {
    const conflict = {
      transactionId: '00000000-0000-7000-8000-000000000011',
      ruleIds: ['00000000-0000-7000-8000-000000000012', '00000000-0000-7000-8000-000000000013'],
    };
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
        conflictCount: 25,
        conflicts: [conflict],
        conflictsTruncated: true,
        samples: [],
        skipped: [],
        deactivatedRuleIds: [],
      }),
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Prévisualiser l’historique' }));
    fireEvent.click(screen.getAllByRole('button', { name: 'Prévisualiser l’historique' }).at(-1)!);

    expect(await screen.findByRole('heading', { name: 'Conflits détectés' })).toBeTruthy();
    expect(screen.getByText('Conflits').parentElement?.textContent).toContain('25');
    expect(screen.getByText('20 exemples maximum sont affichés sur 25 conflits.')).toBeTruthy();
    expect(screen.getByText(conflict.transactionId)).toBeTruthy();
    conflict.ruleIds.forEach((id) => expect(screen.getByText(new RegExp(id))).toBeTruthy());
  });
  it('separates the conflict transaction from the rules label with whitespace', async () => {
    const conflict = {
      transactionId: '00000000-0000-7000-8000-000000000011',
      ruleIds: ['00000000-0000-7000-8000-000000000012'],
    };
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
        conflictCount: 1,
        conflicts: [conflict],
        conflictsTruncated: false,
        samples: [],
        skipped: [],
        deactivatedRuleIds: [],
      }),
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Prévisualiser l’historique' }));
    fireEvent.click(screen.getAllByRole('button', { name: 'Prévisualiser l’historique' }).at(-1)!);

    const transactionCode = await screen.findByText(conflict.transactionId);
    const item = transactionCode.closest('li')!;
    expect(item.textContent).toMatch(new RegExp(`${conflict.transactionId}\\s+Règles`));
  });
  it('shows a rule label instead of a raw id for a known conflicting rule', async () => {
    const conflict = {
      transactionId: '00000000-0000-7000-8000-000000000021',
      ruleIds: [rule.id, '00000000-0000-7000-8000-000000000099'],
    };
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
        conflictCount: 1,
        conflicts: [conflict],
        conflictsTruncated: false,
        samples: [],
        skipped: [],
        deactivatedRuleIds: [],
      }),
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Prévisualiser l’historique' }));
    fireEvent.click(screen.getAllByRole('button', { name: 'Prévisualiser l’historique' }).at(-1)!);

    const transactionCode = await screen.findByText(conflict.transactionId);
    const item = transactionCode.closest('li')!;
    expect(item.textContent).toContain(rule.label);
    expect(item.textContent).not.toContain(rule.id);
    expect(item.textContent).toContain('00000000-0000-7000-8000-000000000099');
  });
  it('refetches the rule list when a preview reports a deactivated rule', async () => {
    const deactivatedRule = { ...rule, active: false, deactivatedReason: 'USER' };
    api.listAccounts.mockImplementation(() => success({ items: [] }));
    api.listCategorizationRules
      .mockImplementationOnce(() => success({ items: [rule], page: 1, perPage: 100, total: 1 }))
      .mockImplementation(() =>
        success({ items: [deactivatedRule], page: 1, perPage: 100, total: 1 }),
      );
    api.previewCategorizationRules.mockImplementation(() =>
      success({
        previewToken: 'token',
        matched: 1,
        wouldChange: 1,
        skippedManual: 0,
        conflictCount: 0,
        conflicts: [],
        conflictsTruncated: false,
        samples: [],
        skipped: [],
        deactivatedRuleIds: [rule.id],
      }),
    );
    renderPage();
    expect(await screen.findByText('Active')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Prévisualiser l’historique' }));
    fireEvent.click(screen.getAllByRole('button', { name: 'Prévisualiser l’historique' }).at(-1)!);

    await waitFor(() => expect(api.listCategorizationRules).toHaveBeenCalledTimes(2));
    expect(await screen.findByText('Désactivée')).toBeTruthy();
  });
  it('paginates through every rule instead of stopping at the first 100', async () => {
    const laterRule = { ...rule, id: '00000000-0000-7000-8000-000000000099', label: 'Page deux' };
    api.listAccounts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 100, total: 0 }),
    );
    api.listCategorizationRules.mockImplementation(({ query }) =>
      success(
        query?.page === 2
          ? { items: [laterRule], page: 2, perPage: 100, total: 101 }
          : { items: [rule], page: 1, perPage: 100, total: 101 },
      ),
    );
    renderPage();

    expect(await screen.findByText(rule.label)).toBeTruthy();
    expect(screen.getByText('Page 1 sur 2')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Page suivante' }));

    expect(await screen.findByText(laterRule.label)).toBeTruthy();
    expect(screen.getByText('Page 2 sur 2')).toBeTruthy();
    expect(api.listCategorizationRules).toHaveBeenLastCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ page: 2, perPage: 100 }) }),
    );
  });
  it('keeps the list mounted and focus on the pagination control while changing page', async () => {
    const laterRule = { ...rule, id: '00000000-0000-7000-8000-000000000099', label: 'Page deux' };
    let resolveSecondPage: (value: unknown) => void = () => undefined;
    const secondPage = new Promise((resolve) => {
      resolveSecondPage = resolve;
    });
    api.listAccounts.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 100, total: 0 }),
    );
    api.listCategorizationRules.mockImplementation(({ query }) =>
      query?.page === 2
        ? secondPage
        : success({ items: [rule], page: 1, perPage: 100, total: 101 }),
    );
    renderPage();

    expect(await screen.findByText(rule.label)).toBeTruthy();
    const next = screen.getByRole('button', { name: 'Page suivante' });
    next.focus();
    fireEvent.click(next);

    // While the second page is loading, the list stays mounted (no loading card) and the
    // keyboard user's focus stays on the control they just activated.
    expect(screen.getByText(rule.label)).toBeTruthy();
    expect(screen.queryByRole('heading', { name: 'Chargement des règles…' })).toBeNull();
    expect(document.activeElement).toBe(next);

    // A second activation while the fetch is in flight must not race ahead to page 3.
    fireEvent.click(next);

    resolveSecondPage(await success({ items: [laterRule], page: 2, perPage: 100, total: 101 }));

    expect(await screen.findByText(laterRule.label)).toBeTruthy();
    expect(screen.getByText('Page 2 sur 2')).toBeTruthy();
    expect(api.listCategorizationRules).not.toHaveBeenCalledWith(
      expect.objectContaining({ query: expect.objectContaining({ page: 3 }) }),
    );
  });
  it('loads every account page before opening a complete scope editor', async () => {
    api.listAccounts.mockImplementation(({ query }) =>
      success(
        query?.page === 2
          ? {
              items: [
                {
                  id: '00000000-0000-7000-8000-000000000102',
                  label: 'Compte de la page deux',
                  assetCode: 'EUR',
                },
              ],
              page: 2,
              perPage: 100,
              total: 101,
            }
          : {
              items: Array.from({ length: 100 }, (_, index) => ({
                id: `00000000-0000-7000-8000-${String(index + 1).padStart(12, '0')}`,
                label: index === 0 ? 'Compte de la page une' : `Compte ${index + 1}`,
                assetCode: 'EUR',
              })),
              page: 1,
              perPage: 100,
              total: 101,
            },
      ),
    );
    api.listCategorizationRules.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 100, total: 0 }),
    );
    renderPage();

    fireEvent.click(await screen.findByRole('button', { name: 'Créer la première règle' }));
    expect(screen.getByText('Compte de la page une · EUR')).toBeTruthy();
    expect(screen.getByText('Compte de la page deux · EUR')).toBeTruthy();
    expect(api.listAccounts).toHaveBeenCalledTimes(2);
  });
  it('keeps editing unavailable when a later account page fails', async () => {
    api.listAccounts.mockImplementation(({ query }) =>
      query?.page === 2
        ? Promise.resolve({ error: {}, response: new Response('', { status: 500 }) })
        : success({ items: [], page: 1, perPage: 100, total: 101 }),
    );
    api.listCategorizationRules.mockImplementation(() =>
      success({ items: [rule], page: 1, perPage: 100, total: 1 }),
    );
    renderPage();

    expect(await screen.findByRole('heading', { name: 'Comptes indisponibles' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Nouvelle règle' }).hasAttribute('disabled')).toBe(
      true,
    );
  });
  it('keeps rule editing unavailable while account references load', async () => {
    api.listAccounts.mockImplementation(() => new Promise(() => undefined));
    api.listCategorizationRules.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 100, total: 0 }),
    );
    renderPage();

    expect(
      await screen.findByText('Chargement des comptes disponibles pour la portée de la règle…'),
    ).toBeTruthy();
    await screen.findByRole('heading', { name: 'Aucune règle' });
    expect(screen.getByRole('button', { name: 'Nouvelle règle' }).hasAttribute('disabled')).toBe(
      true,
    );
    expect(
      screen.getByRole('button', { name: 'Créer la première règle' }).hasAttribute('disabled'),
    ).toBe(true);
  });
  it('surfaces unavailable account references instead of opening an all-accounts editor', async () => {
    api.listAccounts.mockImplementation(() =>
      Promise.resolve({ error: {}, response: new Response('', { status: 500 }) }),
    );
    api.listCategorizationRules.mockImplementation(() =>
      success({ items: [rule], page: 1, perPage: 100, total: 1 }),
    );
    renderPage();

    expect(await screen.findByRole('heading', { name: 'Comptes indisponibles' })).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Nouvelle règle' }).hasAttribute('disabled')).toBe(
      true,
    );
    fireEvent.click(screen.getByRole('button', { name: /Actions de la règle/ }));
    expect(screen.getByRole('button', { name: /^Modifier/ }).hasAttribute('disabled')).toBe(true);
    expect(screen.queryByRole('dialog', { name: 'Nouvelle règle' })).toBeNull();
  });
  it('tells an unauthorized caller to reconnect before editing a rule', async () => {
    api.listAccounts.mockImplementation(() =>
      Promise.resolve({ error: {}, response: new Response('', { status: 401 }) }),
    );
    api.listCategorizationRules.mockImplementation(() =>
      success({ items: [], page: 1, perPage: 100, total: 0 }),
    );
    renderPage();

    expect(
      await screen.findByText('Reconnectez-vous avant de créer ou modifier une règle.'),
    ).toBeTruthy();
    expect(screen.getByRole('button', { name: 'Nouvelle règle' }).hasAttribute('disabled')).toBe(
      true,
    );
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
        conflictCount: 0,
        conflicts: [],
        conflictsTruncated: false,
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
  it('explains a preview rejected for exceeding the safe execution limit', async () => {
    api.listAccounts.mockImplementation(() => success({ items: [] }));
    api.listCategorizationRules.mockImplementation(() =>
      success({ items: [rule], page: 1, perPage: 100, total: 1 }),
    );
    api.previewCategorizationRules.mockImplementation(() =>
      Promise.resolve({
        error: { type: '/problems/rules.execution_limit' },
        response: new Response('', { status: 422 }),
      }),
    );
    renderPage();
    fireEvent.click(await screen.findByRole('button', { name: 'Prévisualiser l’historique' }));
    fireEvent.click(screen.getAllByRole('button', { name: 'Prévisualiser l’historique' }).at(-1)!);

    expect(
      await screen.findByText(
        'Les règles actives ou la période choisie dépassent la limite d’exécution sûre',
        { exact: false },
      ),
    ).toBeTruthy();
  });
});
