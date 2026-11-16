import type { Account, Category, CategorizationRule } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import type { CategorizationRuleErrorKind } from '@/features/categories/rules/categorizationRuleError';
import { RuleForm } from './RuleForm';

const api = vi.hoisted(() => ({ listCategories: vi.fn() }));

vi.mock('@cadran/api-client', async (importOriginal) => ({
  ...(await importOriginal<typeof import('@cadran/api-client')>()),
  ...api,
}));

const category: Category = {
  id: '00000000-0000-7000-8000-0000000000c1',
  type: 'EXPENSE',
  label: 'Courses',
  parentId: null,
  parentLabel: null,
  icon: null,
  color: null,
  effectiveColor: null,
  effectiveIcon: null,
  defaultAnalyticAxes: [],
  budgetIncluded: true,
  sortOrder: 0,
  depth: 1,
  version: 1,
  used: false,
  typeEditable: true,
  typeEditReason: null,
  canAcceptChildren: true,
  archivedAt: null,
  replacement: null,
};

const rule: CategorizationRule = {
  id: '00000000-0000-7000-8000-000000000001',
  label: 'Supermarché',
  priority: 7,
  accountScope: [],
  conditions: {
    amount: null,
    direction: null,
    mcc: '5411',
    text: null,
  },
  targetCategoryId: category.id,
  targetCategoryLabel: category.label,
  targetAxes: [],
  targetCounterparty: null,
  effectiveFrom: '2026-01-01',
  effectiveTo: null,
  active: true,
  deactivatedReason: null,
  appliedCount: 0,
  version: 4,
  createdAt: '2026-01-01T00:00:00+00:00',
  updatedAt: '2026-01-01T00:00:00+00:00',
  archivedAt: null,
};

function renderForm(
  props: { rule?: CategorizationRule; submitError?: CategorizationRuleErrorKind | null } = {},
) {
  api.listCategories.mockImplementation(() =>
    Promise.resolve({
      data: { items: [category], page: 1, pageSize: 50, total: 1 },
      response: new Response('{}', { status: 200 }),
    }),
  );
  const onSubmit = vi.fn();
  const queryClient = new QueryClient({ defaultOptions: { queries: { retry: false } } });
  const accounts = [] as Account[];
  render(
    <QueryClientProvider client={queryClient}>
      <RuleForm
        accounts={accounts}
        onSubmit={onSubmit}
        pending={false}
        rule={props.rule}
        submitError={props.submitError ?? null}
      />
    </QueryClientProvider>,
  );

  return onSubmit;
}

function submit() {
  fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
}

async function chooseCategory() {
  const picker = screen.getByRole('combobox', { name: 'Catégorie cible' });
  fireEvent.focus(picker);
  fireEvent.mouseDown(await screen.findByRole('option', { name: 'Courses' }));
}

function openFamily(name: string) {
  fireEvent.click(screen.getByText(name));
}

describe('RuleForm', () => {
  afterEach(() => {
    cleanup();
    vi.clearAllMocks();
  });

  it('flags every required field and sends nothing while the form is incomplete', () => {
    const onSubmit = renderForm();

    expect(screen.queryByText('Le libellé doit contenir entre 1 et 80 caractères.')).toBeNull();
    submit();

    expect(onSubmit).not.toHaveBeenCalled();
    expect(screen.getByText('Le libellé doit contenir entre 1 et 80 caractères.')).toBeTruthy();
    expect(screen.getByText('Choisissez une catégorie active de cet espace.')).toBeTruthy();
    expect(screen.getByText('Ajoutez au moins une condition complète.')).toBeTruthy();
    expect(screen.getByText(/La période est obligatoire/)).toBeTruthy();
    expect(screen.getByLabelText(/^Libellé/).getAttribute('aria-invalid')).toBe('true');
  });

  it('rejects an invalid priority and a period ending before it starts', () => {
    renderForm({ rule });
    fireEvent.change(screen.getByLabelText(/^Priorité/), { target: { value: '0' } });
    fireEvent.change(screen.getByLabelText(/^Effective à partir du/), {
      target: { value: '2026-03-01' },
    });
    fireEvent.change(screen.getByLabelText(/^Effective jusqu’au/), {
      target: { value: '2026-02-01' },
    });
    submit();

    expect(screen.getByText('La priorité est un entier compris entre 1 et 999.')).toBeTruthy();
    expect(screen.getByLabelText(/^Priorité/).getAttribute('aria-invalid')).toBe('true');
    expect(screen.getByText(/sa fin ne peut pas précéder son début/)).toBeTruthy();
    expect(screen.getByLabelText(/^Effective jusqu’au/).getAttribute('aria-invalid')).toBe('true');
  });

  it('refuses a minimum above the maximum and a bound that is not an exact decimal', () => {
    const onSubmit = renderForm({ rule });
    openFamily('Comparer un montant exact');
    fireEvent.click(screen.getByRole('checkbox', { name: /montant/i }));
    fireEvent.change(screen.getByLabelText('Minimum'), { target: { value: '200.50' } });
    fireEvent.change(screen.getByLabelText('Maximum'), { target: { value: '10' } });
    submit();

    expect(onSubmit).not.toHaveBeenCalled();
    expect(screen.getByText(/un minimum inférieur ou égal au maximum/)).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Minimum'), { target: { value: '12,5' } });
    fireEvent.change(screen.getByLabelText('Maximum'), { target: { value: '300' } });
    submit();
    expect(onSubmit).not.toHaveBeenCalled();

    fireEvent.change(screen.getByLabelText('Minimum'), { target: { value: '12.50' } });
    submit();
    expect(onSubmit).toHaveBeenCalledOnce();
  });

  it('adds and removes a text condition, which empties the rule of conditions', () => {
    const onSubmit = renderForm({
      rule: { ...rule, conditions: { ...rule.conditions, mcc: null } },
    });
    openFamily('Comparer un texte');
    fireEvent.click(screen.getByRole('button', { name: 'Ajouter une condition texte' }));
    fireEvent.change(screen.getByLabelText('Valeur de la condition texte 1'), {
      target: { value: 'CARREFOUR' },
    });
    expect(screen.getByRole('button', { name: 'Supprimer la condition texte 1' })).toBeTruthy();

    fireEvent.click(screen.getByRole('button', { name: 'Supprimer la condition texte 1' }));
    expect(screen.queryByLabelText('Valeur de la condition texte 1')).toBeNull();
    submit();

    expect(onSubmit).not.toHaveBeenCalled();
    expect(screen.getByText('Ajoutez au moins une condition complète.')).toBeTruthy();
  });

  it('builds a create payload with exact decimal strings and trimmed text', async () => {
    const onSubmit = renderForm();
    fireEvent.change(screen.getByLabelText(/^Libellé/), { target: { value: '  Courses  ' } });
    await chooseCategory();
    fireEvent.change(screen.getByLabelText(/^Effective à partir du/), {
      target: { value: '2026-01-01' },
    });
    openFamily('Comparer un montant exact');
    fireEvent.click(screen.getByRole('checkbox', { name: /montant/i }));
    fireEvent.change(screen.getByLabelText('Minimum'), { target: { value: '10.10' } });
    fireEvent.change(screen.getByLabelText('Maximum'), { target: { value: '99.99' } });
    fireEvent.change(screen.getByLabelText(/^Tiers à compléter/), {
      target: { value: '  Carrefour ' },
    });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Essentiel' }));
    submit();

    expect(onSubmit).toHaveBeenCalledOnce();
    const body = onSubmit.mock.calls[0]?.[0];
    expect(body).toEqual({
      accountScope: [],
      conditions: {
        amount: { assetCode: 'EUR', max: '99.99', min: '10.10' },
        direction: null,
        mcc: null,
        text: null,
      },
      effectiveFrom: '2026-01-01',
      effectiveTo: null,
      label: 'Courses',
      priority: 100,
      targetAxes: ['ESSENTIAL'],
      targetCategoryId: category.id,
      targetCounterparty: 'Carrefour',
    });
    expect(typeof body.conditions.amount.min).toBe('string');
  });

  it('prefills an edited rule and sends back its version and active flag', () => {
    const onSubmit = renderForm({ rule: { ...rule, effectiveTo: '2026-12-31' } });

    expect((screen.getByLabelText(/^Libellé/) as HTMLInputElement).value).toBe('Supermarché');
    expect((screen.getByLabelText(/^Priorité/) as HTMLInputElement).value).toBe('7');
    submit();

    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({
      active: true,
      effectiveTo: '2026-12-31',
      label: 'Supermarché',
      priority: 7,
      targetCategoryId: category.id,
      version: 4,
    });
  });

  it.each([
    ['invalid', 'La règle ou la période demandée est invalide.'],
    ['stale', 'Les données ont changé depuis la prévisualisation.'],
    ['network', 'L’opération a échoué. Réessayez.'],
  ] as const)('announces a refused submission of kind %s', (kind, message) => {
    renderForm({ rule, submitError: kind });

    expect(within(screen.getByRole('alert')).getByText(new RegExp(message))).toBeTruthy();
  });

  it('toggles an analytic axis off again and clears the counterparty to null', () => {
    const onSubmit = renderForm({
      rule: { ...rule, targetAxes: ['FIXED'], targetCounterparty: 'Carrefour' },
    });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Fixe' }));
    fireEvent.change(screen.getByLabelText(/^Tiers à compléter/), { target: { value: '   ' } });
    submit();

    expect(onSubmit.mock.calls[0]?.[0]).toMatchObject({ targetAxes: [], targetCounterparty: null });
  });
});
