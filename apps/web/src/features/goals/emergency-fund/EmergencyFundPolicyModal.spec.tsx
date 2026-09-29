import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, waitFor } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { EmergencyFundPolicyModal } from './EmergencyFundPolicyModal';
import { coverageShortfall, defaultPolicy } from './fixtures';

const api = vi.hoisted(() => ({
  readEmergencyFundCoverage: vi.fn(),
  readEmergencyFundPolicy: vi.fn(),
  updateEmergencyFundPolicy: vi.fn(),
  listCategories: vi.fn(),
  getSession: vi.fn(),
}));
vi.mock('@cadran/api-client', async (original) => ({
  ...(await original<typeof import('@cadran/api-client')>()),
  ...api,
}));
const respond = <T,>(data: T, status = 200) =>
  Promise.resolve({
    data: status < 400 ? data : undefined,
    error: status < 400 ? undefined : data,
    response: new Response(JSON.stringify(data), { status }),
  });

function renderModal(close = vi.fn()) {
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <EmergencyFundPolicyModal close={close} />
    </QueryClientProvider>,
  );
  return close;
}

describe('EmergencyFundPolicyModal', () => {
  beforeEach(() => {
    api.readEmergencyFundPolicy.mockImplementation(() => respond(defaultPolicy));
    api.readEmergencyFundCoverage.mockImplementation(() => respond(coverageShortfall));
    api.getSession.mockImplementation(() => respond({ workspace: { baseCurrency: 'EUR' } }));
    api.updateEmergencyFundPolicy.mockImplementation(() => respond(defaultPolicy));
  });
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('saves the default policy at version 0 and closes', async () => {
    const close = renderModal();
    await screen.findByLabelText(/objectif en mois/i);
    fireEvent.change(screen.getByLabelText(/objectif en mois/i), { target: { value: '4,5' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(close).toHaveBeenCalled());
    expect(api.updateEmergencyFundPolicy.mock.calls[0][0].body).toEqual({
      essentialMode: 'AXIS',
      essentialCategoryIds: [],
      referenceMode: 'PREVIOUS_YEAR',
      trailingMonths: null,
      targetMonths: '4.5',
      version: 0,
    });
  });

  it('refuses an out-of-range target without calling the API', async () => {
    renderModal();
    fireEvent.change(await screen.findByLabelText(/objectif en mois/i), {
      target: { value: '60.5' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(await screen.findByText(/de 0 à 60/)).toBeTruthy();
    expect(api.updateEmergencyFundPolicy).not.toHaveBeenCalled();
  });

  it('bounds the trailing months from 1 to 24', async () => {
    renderModal();
    await screen.findByLabelText(/objectif en mois/i);
    fireEvent.click(screen.getByRole('radio', { name: /derniers mois complets/i }));
    fireEvent.change(screen.getByLabelText(/nombre de mois/i), { target: { value: '25' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(await screen.findByText(/entier de 1 à 24/)).toBeTruthy();
    expect(api.updateEmergencyFundPolicy).not.toHaveBeenCalled();
  });

  it('requires a category in categories mode', async () => {
    renderModal();
    await screen.findByLabelText(/objectif en mois/i);
    fireEvent.click(screen.getByRole('radio', { name: /catégories choisies/i }));
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(await screen.findByText(/de 1 à 50 catégories/)).toBeTruthy();
    expect(api.updateEmergencyFundPolicy).not.toHaveBeenCalled();
  });

  it('lists the saved categories and lets one be removed', async () => {
    api.readEmergencyFundPolicy.mockImplementation(() =>
      respond({
        ...defaultPolicy,
        essentialMode: 'CATEGORIES',
        essentialCategories: [{ id: 'c1', label: 'Loyer', archived: false }],
        version: 2,
      }),
    );
    renderModal();
    expect(await screen.findByText('Loyer')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Retirer Loyer' }));
    expect(screen.queryByText('Loyer')).toBeNull();
  });

  it('says the policy changed elsewhere on a 409 and keeps the modal open', async () => {
    api.updateEmergencyFundPolicy.mockImplementation(() =>
      respond({ code: 'version_conflict' }, 409),
    );
    const close = renderModal();
    await screen.findByLabelText(/objectif en mois/i);
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(await screen.findByText(/modifiée ailleurs/)).toBeTruthy();
    expect(close).not.toHaveBeenCalled();
  });

  it('keeps the draft on a 409 and resends it with the reloaded version', async () => {
    api.updateEmergencyFundPolicy.mockImplementationOnce(() =>
      respond({ code: 'version_conflict' }, 409),
    );
    const close = renderModal();
    const target = await screen.findByLabelText(/objectif en mois/i);
    fireEvent.change(target, { target: { value: '8' } });
    api.readEmergencyFundPolicy.mockImplementation(() => respond({ ...defaultPolicy, version: 4 }));
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await screen.findByText(/modifiée ailleurs/);
    expect((screen.getByLabelText(/objectif en mois/i) as HTMLInputElement).value).toBe('8');
    await waitFor(() => expect(api.readEmergencyFundPolicy).toHaveBeenCalledTimes(2));
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    await waitFor(() => expect(close).toHaveBeenCalled());
    expect(api.updateEmergencyFundPolicy.mock.calls[1][0].body).toMatchObject({
      targetMonths: '8',
      version: 4,
    });
  });

  it('names the ineligible category on invalid_emergency_fund_category', async () => {
    api.updateEmergencyFundPolicy.mockImplementation(() =>
      respond({ code: 'invalid_emergency_fund_category' }, 422),
    );
    renderModal();
    await screen.findByLabelText(/objectif en mois/i);
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(await screen.findByText(/n’est pas éligible/)).toBeTruthy();
  });

  it('offers only active budget-included expense categories', async () => {
    const category = (id: string, label: string, budgetIncluded: boolean) => ({
      id,
      label,
      type: 'EXPENSE',
      budgetIncluded,
      archivedAt: null,
      parentId: null,
    });
    api.listCategories.mockImplementation(() =>
      respond({
        items: [category('k1', 'Courses', true), category('k2', 'Hors budget', false)],
        page: 1,
        perPage: 50,
        total: 2,
      }),
    );
    renderModal();
    await screen.findByLabelText(/objectif en mois/i);
    fireEvent.click(screen.getByRole('radio', { name: /catégories choisies/i }));
    fireEvent.focus(screen.getByRole('combobox'));
    expect(await screen.findByText('Courses')).toBeTruthy();
    expect(screen.queryByText('Hors budget')).toBeNull();
  });

  it('shows a generic refusal on a 422', async () => {
    api.updateEmergencyFundPolicy.mockImplementation(() => respond({ code: 'invalid' }, 422));
    renderModal();
    await screen.findByLabelText(/objectif en mois/i);
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(await screen.findByText(/politique est refusée/)).toBeTruthy();
  });

  it('shows an error when the policy cannot be loaded', async () => {
    api.readEmergencyFundPolicy.mockImplementation(() => respond({}, 500));
    renderModal();
    expect(await screen.findByRole('alert')).toBeTruthy();
  });

  it('explains a workspace too large to compute when the policy read is refused', async () => {
    api.readEmergencyFundPolicy.mockImplementation(() =>
      respond({ code: 'emergency_fund_scope_too_large' }, 422),
    );
    renderModal();
    expect(await screen.findByText(/trop de comptes ou de catégories/)).toBeTruthy();
  });
});
