import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { GoalCorrectionPreview, GoalCorrectionPreviewState } from '@cadran/api-client';
import '@/i18n';
import { CorrectionImpactTable } from './CorrectionImpactTable';

const state = (
  goalVersion: number | null,
  expected: string | null,
  status: GoalCorrectionPreviewState['status'] = 'IN_PROGRESS',
  pace: GoalCorrectionPreviewState['pace'] = null,
): GoalCorrectionPreviewState => ({ goalVersion, expected, status, pace });

const month = (
  before: GoalCorrectionPreviewState,
  after: GoalCorrectionPreviewState,
): GoalCorrectionPreview => ({ months: [{ month: '2026-06', before, after }] });

const cells = () => within(screen.getAllByRole('row')[1]!).getAllByRole('cell');

describe('CorrectionImpactTable', () => {
  afterEach(cleanup);

  it('shows where the corrected version hands over to the next one', () => {
    const preview: GoalCorrectionPreview = {
      months: [
        { month: '2026-06', before: state(1, '6000.00'), after: state(3, '9000.00') },
        { month: '2026-07', before: state(2, '8000.00'), after: state(2, '8000.00') },
      ],
    };
    render(<CorrectionImpactTable assetCode="EUR" direction="MINIMUM" preview={preview} />);
    const rows = screen.getAllByRole('row');
    expect(within(rows[1]!).getByText('Version 1 → version 3')).toBeTruthy();
    expect(within(rows[2]!).getByText('Version 2 → version 2')).toBeTruthy();
  });

  it.each([
    ['MINIMUM' as const, 'Non atteint'],
    ['CEILING' as const, 'Dépassé'],
  ])('names a missed %s goal with its own words', (direction, word) => {
    render(
      <CorrectionImpactTable
        assetCode="EUR"
        direction={direction}
        preview={month(state(1, '100.00', 'MET'), state(2, '200.00', 'MISSED'))}
      />,
    );
    expect(cells()[1]!.textContent).toMatch(new RegExp(`· ${word}$`));
    expect(screen.queryByText(/goals\./)).toBeNull();
  });

  it('shows both paces of a yearly goal moved from ahead to behind', () => {
    render(
      <CorrectionImpactTable
        assetCode="EUR"
        direction="MINIMUM"
        preview={month(
          state(1, '1500.03', 'IN_PROGRESS', 'AHEAD'),
          state(2, '1800.00', 'IN_PROGRESS', 'BEHIND'),
        )}
      />,
    );
    expect(cells()[0]!.textContent).toMatch(/· En cours · En avance$/);
    expect(cells()[1]!.textContent).toMatch(/· En cours · En retard$/);
  });

  it('shows no pace for a monthly goal', () => {
    render(
      <CorrectionImpactTable
        assetCode="EUR"
        direction="CEILING"
        preview={month(state(1, '100.00'), state(2, '200.00'))}
      />,
    );
    expect(cells()[0]!.textContent).toMatch(/· En cours$/);
  });

  it('states when no month changes and marks an uncomputable version', () => {
    const { rerender } = render(
      <CorrectionImpactTable assetCode="EUR" direction="MINIMUM" preview={{ months: [] }} />,
    );
    expect(screen.getByText('Cette correction ne modifie aucun mois.')).toBeTruthy();
    rerender(
      <CorrectionImpactTable
        assetCode="EUR"
        direction="MINIMUM"
        preview={month(state(null, null, 'NOT_CALCULABLE'), state(1, '1000.00'))}
      />,
    );
    expect(screen.queryByText(/Version .* → version/)).toBeNull();
  });
});
