import type { GoalSummary } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { BulkGoalForm } from './BulkGoalForm';

const goal = {
  id: 'g1',
  label: 'Épargne',
  version: 2,
  assetCode: 'EUR',
  currentVersion: { valueType: 'AMOUNT', schedule: null },
} as GoalSummary;
const props = () => ({
  goals: [goal],
  month: '2026-11',
  busy: false,
  preview: null,
  previewPending: false,
  error: null,
  onChange: vi.fn(),
  onPreview: vi.fn(),
  onSave: vi.fn(),
  close: vi.fn(),
});
afterEach(cleanup);
describe('bulk goal form', () => {
  it.each([
    ['a', false],
    ['ab', false],
    ['abc', true],
    ['x'.repeat(200), true],
    ['x'.repeat(201), false],
    ['  ab  ', false],
  ])('validates the retroactive reason length (%s)', (reason, valid) => {
    const input = props();
    render(<BulkGoalForm {...input} />);
    fireEvent.click(screen.getByLabelText('Modifier la tolérance'));
    fireEvent.change(screen.getByLabelText('Mois d’effet commun'), {
      target: { value: '2026-10' },
    });
    fireEvent.change(screen.getByLabelText('Motif de la correction rétroactive'), {
      target: { value: reason },
    });
    expect(
      screen
        .getByRole('button', { name: 'Prévisualiser les changements' })
        .hasAttribute('disabled'),
    ).toBe(!valid);
  });
  it('requires a correction reason before previewing a retroactive change', () => {
    const input = props();
    render(<BulkGoalForm {...input} />);
    fireEvent.click(screen.getByLabelText('Modifier la tolérance'));
    fireEvent.change(screen.getByLabelText('Mois d’effet commun'), {
      target: { value: '2026-10' },
    });
    expect(
      screen
        .getByRole('button', { name: 'Prévisualiser les changements' })
        .hasAttribute('disabled'),
    ).toBe(true);
    fireEvent.change(screen.getByLabelText('Motif de la correction rétroactive'), {
      target: { value: 'Correction de saisie' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Prévisualiser les changements' }));
    expect(input.onPreview).toHaveBeenCalledWith({
      items: [
        { goalId: 'g1', expectedVersion: 2, expectedValueType: 'AMOUNT', expectedAssetCode: 'EUR' },
      ],
      effectiveFrom: '2026-10',
      changes: { tolerance: '0' },
      reason: 'Correction de saisie',
    });
  });
  it('keeps the draft after an error and invalidates preview when a field changes', () => {
    const input = props();
    const view = render(<BulkGoalForm {...input} />);
    fireEvent.click(screen.getByLabelText('Modifier la tolérance'));
    fireEvent.change(screen.getByLabelText('Tolérance (EUR)'), { target: { value: '25' } });
    view.rerender(<BulkGoalForm {...input} error="Actualisez la prévisualisation" />);
    expect((screen.getByLabelText('Tolérance (EUR)') as HTMLInputElement).value).toBe('25');
    expect(screen.getByRole('alert').textContent).toBe('Actualisez la prévisualisation');
    expect(input.onChange).toHaveBeenCalledTimes(2);
    fireEvent.click(screen.getByRole('button', { name: 'Annuler' }));
    expect(input.close).toHaveBeenCalledOnce();
    expect(input.onSave).not.toHaveBeenCalled();
  });
  it('explains incompatible units and custom calendars without allowing target overwrite', () => {
    const input = props();
    const custom = {
      ...goal,
      currentVersion: { ...goal.currentVersion, schedule: { mode: 'CUSTOM' } },
    } as GoalSummary;
    const view = render(<BulkGoalForm {...input} goals={[custom]} />);
    expect(screen.getByLabelText('Modifier la cible').hasAttribute('disabled')).toBe(true);
    expect(screen.getByLabelText('Modifier la tolérance').hasAttribute('disabled')).toBe(false);
    view.rerender(
      <BulkGoalForm {...input} goals={[goal, { ...goal, id: 'g2', assetCode: 'USD' }]} />,
    );
    expect(screen.getByRole('alert').textContent).toContain('devises différents');
    expect(screen.getByLabelText('Modifier la tolérance').hasAttribute('disabled')).toBe(true);
  });
  it('keeps known labels and goal names while preview values load and disables repeated submission', () => {
    render(<BulkGoalForm {...props()} busy previewPending />);
    expect(screen.getAllByText('Épargne')).toHaveLength(2);
    expect(screen.getByText('Cible avant')).toBeTruthy();
    expect(document.querySelectorAll('[data-skeleton]').length).toBeGreaterThan(0);
    expect(
      screen.getByRole('button', { name: 'Appliquer à la sélection' }).hasAttribute('disabled'),
    ).toBe(true);
  });
});
