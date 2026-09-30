import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import type { GoalCorrectionPreview } from '@cadran/api-client';
import '@/i18n';
import { CorrectionImpactTable } from './CorrectionImpactTable';

const state = (goalVersion: number | null, expected: string | null) => ({
  goalVersion,
  expected,
  status: 'ON_TRACK' as const,
});

describe('CorrectionImpactTable', () => {
  afterEach(cleanup);

  it('shows where the corrected version hands over to the next one', () => {
    const preview: GoalCorrectionPreview = {
      months: [
        { month: '2026-06', before: state(1, '6000.00'), after: state(3, '9000.00') },
        { month: '2026-07', before: state(2, '8000.00'), after: state(2, '8000.00') },
      ],
    };
    render(<CorrectionImpactTable assetCode="EUR" preview={preview} />);
    const rows = screen.getAllByRole('row');
    expect(within(rows[1]!).getByText('Version 1 → version 3')).toBeTruthy();
    expect(within(rows[2]!).getByText('Version 2 → version 2')).toBeTruthy();
  });

  it('states when no month changes and marks an uncomputable version', () => {
    const { rerender } = render(<CorrectionImpactTable assetCode="EUR" preview={{ months: [] }} />);
    expect(screen.getByText('Cette correction ne modifie aucun mois.')).toBeTruthy();
    rerender(
      <CorrectionImpactTable
        assetCode="EUR"
        preview={{
          months: [{ month: '2026-01', before: state(null, null), after: state(1, '1000.00') }],
        }}
      />,
    );
    expect(screen.queryByText(/Version .* → version/)).toBeNull();
  });
});
