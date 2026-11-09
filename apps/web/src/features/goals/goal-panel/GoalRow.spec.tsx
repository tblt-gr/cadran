import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { goalEvaluation } from '@/features/goals/goal-mark/goalEvaluationFixtures';
import { GoalRow } from './GoalRow';

afterEach(cleanup);
it('renders the server start, dated valuation and behind pace for a yearly stock goal', () => {
  const evaluation = goalEvaluation({
    metric: 'NET_WORTH',
    periodicity: 'YEARLY',
    direction: 'MINIMUM',
    status: 'IN_PROGRESS',
    start: { value: '10000', assetCode: 'EUR' },
    target: { value: '20000', assetCode: 'EUR' },
    achieved: { value: '12000', assetCode: 'EUR' },
    pace: { expected: '12500', status: 'BEHIND' },
    valuationDate: '2026-04-01',
    valuationQuality: 'CURRENT',
    explanationUrl: '/accounts',
    formula: {
      key: 'goals.formula.stock',
      operands: { target: '20000', achieved: '12000', tolerance: null },
    },
  });
  render(
    <ul>
      <GoalRow
        row={{ evaluation, summary: null, replaced: [] }}
        handlers={{ onEdit: vi.fn(), onHistory: vi.fn(), onStop: vi.fn(), onDelete: vi.fn() }}
      />
    </ul>,
  );
  expect(screen.queryByText(/Départ 10/)).toBeNull();
  fireEvent.click(screen.getByRole('button', { name: 'Expliquer l’objectif « Essentiel »' }));
  expect(screen.getByRole('dialog', { name: 'Détails de l’objectif « Essentiel »' })).toBeTruthy();
  expect(screen.getByText(/Départ 10/)).toBeTruthy();
  expect(screen.getByText(/2026-04-01/)).toBeTruthy();
  expect(
    screen
      .getByRole('link', { name: 'Voir la composition du patrimoine net' })
      .getAttribute('href'),
  ).toBe('/accounts');
  expect(document.querySelector('[data-tone]')?.getAttribute('data-tone')).toBe('negative');
});
