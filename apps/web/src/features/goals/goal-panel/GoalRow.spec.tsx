import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, expect, it, vi } from 'vitest';
import '@/i18n';
import { goalEvaluation } from '@/features/goals/goal-mark/goalEvaluationFixtures';
import { GoalRow } from './GoalRow';

afterEach(cleanup);
it.each([
  ['MET', true, true],
  ['MET', false, false],
  ['MET', null, false],
  ['IN_PROGRESS', true, false],
  ['MISSED', false, false],
  ['NOT_CALCULABLE', null, false],
] as const)(
  'announces tolerance only for an achieved goal (%s, %s)',
  (status, withinTolerance, visible) => {
    render(
      <ul>
        <GoalRow
          row={{
            evaluation: goalEvaluation({ status, withinTolerance }),
            summary: null,
            replaced: [],
          }}
          handlers={{ onEdit: vi.fn(), onHistory: vi.fn(), onStop: vi.fn(), onDelete: vi.fn() }}
        />
      </ul>,
    );
    if (visible) expect(screen.getByText('Atteint grâce à la tolérance.')).toBeTruthy();
    else expect(screen.queryByText('Atteint grâce à la tolérance.')).toBeNull();
  },
);
it('shows no-data total income as non-calculable without a zero achieved value', () => {
  render(
    <ul>
      <GoalRow
        row={{
          evaluation: goalEvaluation({
            metric: 'TOTAL_INCOME',
            scopeType: 'WORKSPACE',
            scopeRef: null,
            direction: 'MINIMUM',
            achieved: null,
            status: 'NOT_CALCULABLE',
            reason: 'NO_DATA',
          }),
          summary: null,
          replaced: [],
        }}
        handlers={{ onEdit: vi.fn(), onHistory: vi.fn(), onStop: vi.fn(), onDelete: vi.fn() }}
      />
    </ul>,
  );
  expect(screen.getByText('Non calculable')).toBeTruthy();
  expect(screen.getByText('Aucune donnée pour cette période')).toBeTruthy();
  expect(screen.queryByRole('meter')).toBeNull();
});
it.each(['TOTAL_INCOME', 'TOTAL_SPENDING'] as const)(
  'renders a %s workspace row with the server status and values',
  (metric) => {
    render(
      <ul>
        <GoalRow
          row={{
            evaluation: goalEvaluation({
              metric,
              scopeType: 'WORKSPACE',
              scopeRef: null,
              label: 'Total mensuel',
              direction: metric === 'TOTAL_INCOME' ? 'MINIMUM' : 'CEILING',
              target: { value: '3000', assetCode: 'EUR' },
              achieved: { value: '2950', assetCode: 'EUR' },
              status: 'IN_PROGRESS',
            }),
            summary: null,
            replaced: [],
          }}
          handlers={{ onEdit: vi.fn(), onHistory: vi.fn(), onStop: vi.fn(), onDelete: vi.fn() }}
        />
      </ul>,
    );
    expect(screen.getByRole('heading', { name: 'Total mensuel' })).toBeTruthy();
    expect(screen.getByText('En cours')).toBeTruthy();
    expect(screen.getByRole('listitem').textContent).toMatch(/2\s950,00/);
    expect(screen.getByRole('listitem').textContent).toMatch(/3\s000,00/);
    expect(screen.getByText('Tout l’espace')).toBeTruthy();
  },
);
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
