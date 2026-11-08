import { cleanup, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import type { GoalSchedule, GoalVersionHistoryItem } from '@cadran/api-client';
import '@/i18n';
import { GoalHistoryModal } from './GoalHistoryModal';
import { GoalHistoryTableSkeleton } from './GoalHistoryTableSkeleton';

function schedule(year: string, amount: string): GoalSchedule {
  const part = (month: number) => ({
    month: `${year}-${String(month).padStart(2, '0')}`,
    amount,
    cumulative: amount,
  });
  return {
    mode: 'LINEAR',
    parts: [
      part(1),
      part(2),
      part(3),
      part(4),
      part(5),
      part(6),
      part(7),
      part(8),
      part(9),
      part(10),
      part(11),
      part(12),
    ],
  };
}

const items: GoalVersionHistoryItem[] = [
  {
    versionNo: 2,
    effectiveFrom: '2026-01',
    retroactive: true,
    reason: 'Montant confirmé',
    createdAt: '2026-09-10T09:00:00Z',
    createdBy: { id: '00000000-0000-7000-8000-000000000001', displayName: 'Owner' },
    year: 2026,
    targetAmount: '14400.00',
    ratio: null,
    schedule: schedule('2026', '1200.00'),
    changes: [{ field: 'targetAmount' as const, before: '12000.00', after: '14400.00' }],
  },
  {
    versionNo: 1,
    effectiveFrom: '2026-01',
    retroactive: false,
    reason: null,
    createdAt: '2026-01-01T09:00:00Z',
    createdBy: { id: '00000000-0000-7000-8000-000000000001', displayName: 'Owner' },
    year: 2026,
    targetAmount: '12000.00',
    ratio: null,
    schedule: schedule('2026', '1000.00'),
    changes: [],
  },
];

function rowOf(version: number) {
  return screen.getByRole('row', { name: new RegExp(`^${version}\\s`) });
}

describe('GoalHistoryModal', () => {
  afterEach(cleanup);

  it('shows every version in one always visible table, newest first, without any disclosure', () => {
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={items} />);
    expect(document.querySelector('details')).toBeNull();
    const table = screen.getByRole('table', { name: 'Versions enregistrées' });
    expect(
      within(table)
        .getAllByRole('columnheader')
        .map((header) => header.textContent),
    ).toEqual(['Version', 'Modifiée le', 'À partir de', 'Évolution']);
    const rows = within(table).getAllByRole('row').slice(1);
    expect(rows).toHaveLength(2);
    expect(within(rows[0]!).getByRole('rowheader').textContent).toBe('2');
    expect(within(rows[1]!).getByRole('rowheader').textContent).toBe('1');
  });

  it('gives each cell its mobile label, the modification date and the first month', () => {
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={items} />);
    const row = rowOf(2);
    expect(
      within(row)
        .getAllByRole('cell')
        .map((cell) => cell.getAttribute('data-label')),
    ).toEqual(['Modifiée le', 'À partir de', 'Évolution']);
    expect(within(row).getByText(/10 sept\. 2026/)).toBeTruthy();
    expect(within(row).getByText(/janvier 2026/i)).toBeTruthy();
  });

  it('flags a retroactive correction with its reason', () => {
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={items} />);
    expect(within(rowOf(2)).getByText('Rétroactive')).toBeTruthy();
    expect(within(rowOf(2)).getByText('Motif : Montant confirmé')).toBeTruthy();
    expect(within(rowOf(1)).queryByText('Rétroactive')).toBeNull();
  });

  it('shows the previous and the new value, and a dash when a version changed nothing', () => {
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={items} />);
    const evolution = within(rowOf(2)).getAllByRole('cell')[2]!;
    expect(evolution.textContent).toMatch(/12\s000,00\s€.*→.*14\s400,00\s€/);
    expect(within(rowOf(1)).getAllByRole('cell')[2]!.textContent).toBe('-');
  });

  it('summarizes a custom schedule instead of listing twelve amounts', () => {
    const custom = [
      {
        ...items[0]!,
        changes: [
          {
            field: 'schedule' as const,
            before: Array(12).fill('1000.00') as unknown as string,
            after: ['1.00', ...Array(11).fill('2.00')] as unknown as string,
          },
        ],
      },
    ];
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={custom} />);
    expect(screen.getByText(/Répartition personnalisée/)).toBeTruthy();
  });

  it('formats rolling ratios as percentages rather than currency', () => {
    const rolling = [
      {
        ...items[0]!,
        targetAmount: null,
        ratio: '0.35',
        schedule: null,
        changes: [{ field: 'ratio' as const, before: '0.30', after: '0.35' }],
      },
    ];
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={rolling} />);
    expect(screen.getByText(/35,00 %/)).toBeTruthy();
  });

  it('names the value types of a changed value type instead of printing their codes', () => {
    const changed = [
      {
        ...items[0]!,
        changes: [{ field: 'valueType' as const, before: 'AMOUNT', after: 'ROLLING_INCOME_SHARE' }],
      },
    ];
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={changed} />);
    const evolution = within(rowOf(2)).getAllByRole('cell')[2]!;
    expect(evolution.textContent).toMatch(/Montant.*Part du revenu moyen sur 12 mois/);
  });

  it('explains the empty history state', () => {
    render(<GoalHistoryModal assetCode="EUR" close={vi.fn()} items={[]} />);
    expect(screen.getByText('Aucune version n’est disponible.')).toBeTruthy();
  });

  it('has a skeleton that mirrors the table: same caption and headers, pending values', () => {
    render(<GoalHistoryTableSkeleton />);
    const table = screen.getByRole('table', { name: 'Versions enregistrées' });
    expect(
      within(table)
        .getAllByRole('columnheader')
        .map((header) => header.textContent),
    ).toEqual(['Version', 'Modifiée le', 'À partir de', 'Évolution']);
    expect(within(table).getAllByRole('row')).toHaveLength(4);
  });
});
