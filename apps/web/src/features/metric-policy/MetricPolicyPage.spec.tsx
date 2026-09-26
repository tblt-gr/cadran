import type { MetricPolicy, MetricPolicyCatalog } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { MetricPolicyPage } from './MetricPolicyPage';

const api = vi.hoisted(() => ({
  activateMetricPolicy: vi.fn(),
  createMetricPolicy: vi.fn(),
  listMetricPolicies: vi.fn(),
}));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const v1: MetricPolicy = {
  version: 1,
  label: 'Définition de trésorerie',
  cashExcludedAccountKinds: ['EMPLOYEE_BENEFIT'],
  savingsRateFormula: 'BUDGET_SURPLUS_OVER_CASH_INCOME',
  netSavingsRateFormula: 'NET_SAVINGS_TRANSFERS_OVER_CASH_INCOME',
  createdAt: null,
  system: true,
};
const v2: MetricPolicy = {
  ...v1,
  version: 2,
  label: 'Tout compte',
  cashExcludedAccountKinds: [],
  createdAt: '2026-05-10T08:00:00+00:00',
  system: false,
};

const onlySystem: MetricPolicyCatalog = {
  active: v1,
  activeVersion: 1,
  activeSince: null,
  versions: [v1],
};
const withV2: MetricPolicyCatalog = {
  active: v1,
  activeVersion: 1,
  activeSince: null,
  versions: [v1, v2],
};

function ok<T>(data: T, status = 200) {
  return Promise.resolve({ data, response: new Response(JSON.stringify(data), { status }) });
}

function problem(status: number, code?: string) {
  const body = { type: 'about:blank', title: 'Refusé', status, detail: 'Refusé.', code };
  return Promise.resolve({
    error: body,
    response: new Response(JSON.stringify(body), { status }),
  });
}

function renderPage() {
  return render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <MetricPolicyPage />
    </QueryClientProvider>,
  );
}

describe('MetricPolicyPage', () => {
  afterEach(() => {
    cleanup();
    vi.resetAllMocks();
  });

  it('announces loading, then shows the active definition in plain language', async () => {
    api.listMetricPolicies.mockReturnValue(ok(onlySystem));
    renderPage();

    expect(screen.getByRole('status').textContent).toContain('Chargement');
    const card = await screen.findByRole('region', { name: 'Politique active' });
    expect(card.textContent).toContain('Version 1');
    expect(card.textContent).toContain('Définition de trésorerie');
    expect(card.textContent).toContain('Avantage salarié');
    expect(card.textContent).toContain('Solde budgétaire');
    expect(screen.getByRole('link', { name: 'Profil' })).toBeTruthy();
  });

  it('shows an empty history message when only the built-in version exists', async () => {
    api.listMetricPolicies.mockReturnValue(ok(onlySystem));
    renderPage();

    expect(await screen.findByText('Aucune version personnalisée pour le moment.')).toBeTruthy();
    const row = screen.getByRole('row', { name: /Définition de trésorerie/ });
    expect(
      within(row).getByRole('button', { name: 'Activer la version 1' }).hasAttribute('disabled'),
    ).toBe(true);
  });

  it('offers a retry on error and no retry when unauthorized', async () => {
    api.listMetricPolicies.mockReturnValueOnce(problem(500));
    renderPage();
    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('indisponible');

    api.listMetricPolicies.mockReturnValue(ok(onlySystem));
    fireEvent.click(within(alert).getByRole('button', { name: 'Réessayer' }));
    await screen.findByRole('region', { name: 'Politique active' });

    cleanup();
    api.listMetricPolicies.mockReturnValue(problem(403));
    renderPage();
    const denied = await screen.findByRole('alert');
    expect(within(denied).queryByRole('button', { name: 'Réessayer' })).toBeNull();
  });

  it('creates a version from a label and excluded kinds', async () => {
    api.listMetricPolicies.mockReturnValue(ok(onlySystem));
    api.createMetricPolicy.mockReturnValue(ok(v2, 201));
    renderPage();
    await screen.findByRole('region', { name: 'Politique active' });

    fireEvent.click(screen.getByRole('button', { name: 'Nouvelle version' }));
    const dialog = screen.getByRole('dialog', { name: 'Nouvelle version de la politique' });
    fireEvent.change(within(dialog).getByLabelText('Libellé'), {
      target: { value: ' Tout compte ' },
    });
    fireEvent.click(within(dialog).getByRole('checkbox', { name: 'Espèces' }));
    fireEvent.click(within(dialog).getByRole('button', { name: 'Créer la version' }));

    await waitFor(() => expect(api.createMetricPolicy).toHaveBeenCalledTimes(1));
    expect(api.createMetricPolicy.mock.calls[0]![0].body).toEqual({
      label: 'Tout compte',
      cashExcludedAccountKinds: ['CASH'],
    });
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });

  it('refuses an empty label without calling the API and reports a duplicate definition', async () => {
    api.listMetricPolicies.mockReturnValue(ok(onlySystem));
    api.createMetricPolicy.mockReturnValue(problem(409, 'policy_definition_exists'));
    renderPage();
    await screen.findByRole('region', { name: 'Politique active' });
    fireEvent.click(screen.getByRole('button', { name: 'Nouvelle version' }));
    const dialog = screen.getByRole('dialog');

    fireEvent.click(within(dialog).getByRole('button', { name: 'Créer la version' }));
    expect(api.createMetricPolicy).not.toHaveBeenCalled();
    expect(within(dialog).getByRole('alert').textContent).toContain('libellé');

    fireEvent.change(within(dialog).getByLabelText('Libellé'), { target: { value: 'Copie' } });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Créer la version' }));
    expect((await within(dialog).findByRole('alert')).textContent).toContain('existe déjà');
  });

  it('activates a version after showing before and after and the closed-month rule', async () => {
    api.listMetricPolicies.mockReturnValue(ok(withV2));
    api.activateMetricPolicy.mockReturnValue(
      ok({ active: v2, activeSince: '2026-09-26T10:00:00+00:00' }),
    );
    renderPage();
    await screen.findByRole('region', { name: 'Politique active' });

    fireEvent.click(screen.getByRole('button', { name: 'Activer la version 2' }));
    const dialog = screen.getByRole('dialog', { name: 'Activer la version 2' });
    expect(dialog.textContent).toContain('Avant');
    expect(dialog.textContent).toContain('Après');
    expect(dialog.textContent).toContain('mois clôturés conservent leur version');
    fireEvent.change(within(dialog).getByLabelText('Motif (facultatif)'), {
      target: { value: 'Titres-restaurant inclus' },
    });
    fireEvent.click(within(dialog).getByRole('button', { name: 'Activer' }));

    await waitFor(() => expect(api.activateMetricPolicy).toHaveBeenCalledTimes(1));
    expect(api.activateMetricPolicy.mock.calls[0]![0].body).toEqual({
      version: 2,
      expectedActiveVersion: 1,
      reason: 'Titres-restaurant inclus',
    });
  });

  it('explains a stale active version and reloads the catalog', async () => {
    api.listMetricPolicies.mockReturnValue(ok(withV2));
    api.activateMetricPolicy.mockReturnValue(problem(409, 'stale_active_policy'));
    renderPage();
    await screen.findByRole('region', { name: 'Politique active' });
    fireEvent.click(screen.getByRole('button', { name: 'Activer la version 2' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Activer' }));

    expect((await within(dialog).findByRole('alert')).textContent).toContain(
      'La version active a changé',
    );
    await waitFor(() => expect(api.listMetricPolicies.mock.calls.length).toBeGreaterThan(1));
  });

  it('closes the modal with Escape', async () => {
    api.listMetricPolicies.mockReturnValue(ok(withV2));
    renderPage();
    await screen.findByRole('region', { name: 'Politique active' });
    fireEvent.click(screen.getByRole('button', { name: 'Nouvelle version' }));
    fireEvent.keyDown(document, { key: 'Escape' });

    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
  });

  it('shows an explicit error when the active version cannot be loaded and lets it be fixed', async () => {
    api.listMetricPolicies.mockReturnValue(
      ok({
        active: null,
        activeVersion: 7,
        activeSince: '2026-05-10T08:00:00+00:00',
        versions: [v1, v2],
      }),
    );
    api.activateMetricPolicy.mockReturnValue(
      ok({ active: v2, activeSince: '2026-09-26T10:00:00+00:00' }),
    );
    renderPage();

    const alert = await screen.findByRole('alert');
    expect(alert.textContent).toContain('Version 7 introuvable');
    fireEvent.click(screen.getByRole('button', { name: 'Activer la version 2' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Activer' }));

    await waitFor(() => expect(api.activateMetricPolicy).toHaveBeenCalledTimes(1));
    expect(api.activateMetricPolicy.mock.calls[0]![0].body).toEqual({
      version: 2,
      expectedActiveVersion: 7,
    });
  });

  it('explains the activation limit', async () => {
    api.listMetricPolicies.mockReturnValue(ok(withV2));
    api.activateMetricPolicy.mockReturnValue(problem(409, 'activation_limit'));
    renderPage();
    await screen.findByRole('region', { name: 'Politique active' });
    fireEvent.click(screen.getByRole('button', { name: 'Activer la version 2' }));
    const dialog = screen.getByRole('dialog');
    fireEvent.click(within(dialog).getByRole('button', { name: 'Activer' }));

    expect((await within(dialog).findByRole('alert')).textContent).toContain(
      'nombre maximal d’activations',
    );
  });
});
