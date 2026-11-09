import type { CreateGoalRequest, GoalSummary } from '@cadran/api-client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { GoalForm } from './GoalForm';

const existing: GoalSummary = {
  id: '00000000-0000-7000-8000-000000000001',
  label: 'Courses',
  periodicity: 'MONTHLY',
  startPeriod: '2026-01',
  endPeriod: null,
  metric: 'CATEGORY_SPENDING',
  direction: 'CEILING',
  scope: {
    type: 'CATEGORY',
    id: '00000000-0000-7000-8000-0000000000c1',
    label: 'Courses',
    archived: false,
  },
  assetCode: 'EUR',
  currentVersion: {
    versionNo: 1,
    effectiveFrom: '2026-01',
    valueType: 'AMOUNT',
    targetAmount: '400.00',
    ratio: null,
    tolerance: '0',
    schedule: null,
  },
  version: 1,
  warnings: [],
  status: null,
};

function renderForm(goal?: GoalSummary) {
  const onSubmit = vi.fn();
  render(
    <QueryClientProvider
      client={new QueryClient({ defaultOptions: { queries: { retry: false } } })}
    >
      <GoalForm
        baseCurrency="EUR"
        goal={goal}
        onSubmit={onSubmit}
        pending={false}
        submitError={null}
        submitMismatch={null}
      />
    </QueryClientProvider>,
  );
  return onSubmit;
}

const valueTypeOptions = () =>
  [...(screen.getByLabelText('Type de valeur') as HTMLSelectElement).options].map(
    (option) => option.value,
  );

describe('GoalForm', () => {
  beforeEach(() => {
    vi.useFakeTimers({ toFake: ['Date'] });
    vi.setSystemTime(new Date('2026-09-15T10:00:00Z'));
  });
  afterEach(() => {
    cleanup();
    vi.useRealTimers();
  });

  it('saves a stock minimum with an end-of-period value and amount-only target', () => {
    const submit = renderForm();
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Patrimoine' } });
    fireEvent.change(screen.getByLabelText('Indicateur'), { target: { value: 'NET_WORTH' } });
    expect(valueTypeOptions()).toEqual(['AMOUNT']);
    expect(screen.queryByLabelText('Périmètre')).toBeNull();
    fireEvent.change(screen.getByLabelText('Valeur à la fin de la période'), {
      target: { value: '20000' },
    });
    fireEvent.change(screen.getByLabelText('Sens de l’objectif'), { target: { value: 'CEILING' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(submit).toHaveBeenCalledWith(
      expect.objectContaining({
        metric: 'NET_WORTH',
        direction: 'CEILING',
        scope: { type: 'WORKSPACE', ref: null },
        targetAmount: { value: '20000', assetCode: 'EUR' },
      }),
    );
  });

  it('keeps an empty tolerance strict and saves an exact amount margin', () => {
    const submit = renderForm();
    const tolerance = screen.getByLabelText('Tolérance');
    expect((tolerance as HTMLInputElement).value).toBe('');
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Épargne' } });
    fireEvent.change(screen.getByLabelText('Montant cible'), { target: { value: '800' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(submit.mock.calls[0]![0].tolerance).toBe('0');
    fireEvent.change(tolerance, { target: { value: '50.01' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(submit.mock.calls[1]![0].tolerance).toBe('50.01');
  });

  it.each(['-1', '801', 'bad'])('refuses invalid tolerance %s on its own field', (value) => {
    const submit = renderForm();
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Épargne' } });
    fireEvent.change(screen.getByLabelText('Montant cible'), { target: { value: '800' } });
    fireEvent.change(screen.getByLabelText('Tolérance'), { target: { value } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(screen.getByLabelText('Tolérance').getAttribute('aria-invalid')).toBe('true');
    expect(submit).not.toHaveBeenCalled();
  });

  it('converts a point tolerance exactly for a rate and clears it when switching units', () => {
    const submit = renderForm();
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Épargne' } });
    fireEvent.change(screen.getByLabelText('Indicateur'), { target: { value: 'SAVINGS_RATE' } });
    fireEvent.change(screen.getByLabelText('Taux cible'), { target: { value: '15' } });
    fireEvent.change(screen.getByLabelText('Tolérance'), { target: { value: '1' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(submit.mock.calls[0]![0].tolerance).toBe('0.01');
    fireEvent.change(screen.getByLabelText('Indicateur'), { target: { value: 'BUDGET_SURPLUS' } });
    expect((screen.getByLabelText('Tolérance') as HTMLInputElement).value).toBe('');
  });

  it('prefills an existing tolerance and sends zero when cleared', () => {
    const submit = renderForm({
      ...existing,
      currentVersion: { ...existing.currentVersion, tolerance: '20' },
    });
    const field = screen.getByLabelText('Tolérance');
    expect((field as HTMLInputElement).value).toBe('20');
    fireEvent.change(field, { target: { value: '' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));
    expect(submit.mock.calls[0]![0].tolerance).toBe('0');
  });

  it('starts as a monthly goal from the current month with no end', () => {
    renderForm();

    expect((screen.getByRole('radio', { name: 'Mensuel' }) as HTMLInputElement).checked).toBe(true);
    expect((screen.getByLabelText('Début') as HTMLInputElement).value).toBe('2026-09');
    expect((screen.getByRole('checkbox', { name: 'Sans fin' }) as HTMLInputElement).checked).toBe(
      true,
    );
    expect((screen.getByLabelText('Fin') as HTMLInputElement).disabled).toBe(true);
  });

  it('sets the end to the start with the one-off shortcut and follows a start change', () => {
    const onSubmit = renderForm();
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Courses' } });
    fireEvent.change(screen.getByLabelText('Montant cible'), { target: { value: '400.00' } });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Un seul mois' }));

    expect((screen.getByLabelText('Fin') as HTMLInputElement).value).toBe('2026-09');
    expect((screen.getByRole('checkbox', { name: 'Sans fin' }) as HTMLInputElement).checked).toBe(
      false,
    );
    fireEvent.change(screen.getByLabelText('Début'), { target: { value: '2026-11' } });
    expect((screen.getByLabelText('Fin') as HTMLInputElement).value).toBe('2026-11');
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    const request = onSubmit.mock.calls[0]![0] as CreateGoalRequest;
    expect(request.startPeriod).toBe('2026-11');
    expect(request.endPeriod).toBe('2026-11');
  });

  it('refuses an end before the start and flags the end field', () => {
    const onSubmit = renderForm();
    fireEvent.change(screen.getByLabelText('Libellé'), { target: { value: 'Courses' } });
    fireEvent.change(screen.getByLabelText('Montant cible'), { target: { value: '400.00' } });
    fireEvent.click(screen.getByRole('checkbox', { name: 'Sans fin' }));
    fireEvent.change(screen.getByLabelText('Fin'), { target: { value: '2026-03' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    expect(screen.getByLabelText('Fin').getAttribute('aria-invalid')).toBe('true');
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it('switches to years: a year input, a year shortcut and the yearly calendar', () => {
    renderForm();
    fireEvent.click(screen.getByRole('radio', { name: 'Annuel' }));

    expect((screen.getByLabelText('Début') as HTMLInputElement).value).toBe('2026');
    expect(screen.getByRole('checkbox', { name: 'Une seule année' })).toBeTruthy();
    expect(screen.getByRole('radio', { name: 'Personnalisé' })).toBeTruthy();
    fireEvent.click(screen.getByRole('radio', { name: 'Mensuel' }));
    expect(screen.queryByRole('radio', { name: 'Personnalisé' })).toBeNull();
  });

  it('offers the value types the metric and periodicity accept', () => {
    renderForm();

    expect(valueTypeOptions()).toEqual(['AMOUNT', 'INCOME_SHARE', 'ROLLING_INCOME_SHARE']);
    fireEvent.click(screen.getByRole('radio', { name: 'Annuel' }));
    expect(valueTypeOptions()).toEqual(['AMOUNT', 'INCOME_SHARE']);
    expect(screen.getByRole('option', { name: 'Part du revenu de l’année' })).toBeTruthy();

    fireEvent.change(screen.getByLabelText('Indicateur'), {
      target: { value: 'NET_SAVINGS_RATE' },
    });
    expect(valueTypeOptions()).toEqual(['RATE']);
    expect((screen.getByLabelText('Type de valeur') as HTMLSelectElement).disabled).toBe(true);
    expect(screen.getByLabelText('Taux cible')).toBeTruthy();
  });

  it('falls back to an allowed value type and clears the value when the periodicity removes the chosen one', () => {
    renderForm();
    fireEvent.change(screen.getByLabelText('Type de valeur'), {
      target: { value: 'ROLLING_INCOME_SHARE' },
    });
    fireEvent.change(screen.getByLabelText('Pourcentage'), { target: { value: '30' } });
    fireEvent.click(screen.getByRole('radio', { name: 'Annuel' }));

    expect((screen.getByLabelText('Type de valeur') as HTMLSelectElement).value).toBe('AMOUNT');
    expect((screen.getByLabelText('Montant cible') as HTMLInputElement).value).toBe('');
  });

  it('keeps the periodicity, metric, scope and period of an existing goal read-only', () => {
    renderForm(existing);

    expect((screen.getByLabelText('Indicateur') as HTMLSelectElement).disabled).toBe(true);
    expect((screen.getByLabelText('Début') as HTMLInputElement).disabled).toBe(true);
    expect((screen.getByLabelText('Début') as HTMLInputElement).value).toBe('2026-01');
    expect((screen.getByLabelText('Fin') as HTMLInputElement).disabled).toBe(true);
    expect(
      (screen.getByRole('radio', { name: 'Mensuel' }).closest('fieldset') as HTMLFieldSetElement)
        .disabled,
    ).toBe(true);
  });

  it('updates an existing goal with its value type, exact amount and effective month only', () => {
    const onSubmit = renderForm(existing);
    fireEvent.change(screen.getByLabelText('Montant cible'), { target: { value: '450.00' } });
    fireEvent.click(screen.getByRole('button', { name: 'Enregistrer' }));

    expect(onSubmit).toHaveBeenCalledWith({
      label: 'Courses',
      version: 1,
      valueType: 'AMOUNT',
      effectiveFrom: '2026-09',
      reason: null,
      targetAmount: { value: '450.00', assetCode: 'EUR' },
      tolerance: '0',
    });
  });
});
