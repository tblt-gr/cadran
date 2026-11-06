import type { BulkEditPreview } from '@cadran/api-client';
import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import type { BulkItem } from '@/features/transactions/bulk-selection/useBulkSelection';
import { BulkMovePreview } from './BulkMovePreview';

const A = '00000000-0000-7000-8000-000000000001';
const C = '00000000-0000-7000-8000-000000000003';

const items: BulkItem[] = [
  {
    id: A,
    bookedOn: '2026-10-03',
    amount: { value: '-42.10', assetCode: 'EUR' },
    rawLabel: 'CB A',
    displayLabel: 'CB A',
  },
  {
    id: C,
    bookedOn: '2026-10-05',
    amount: { value: '-10.00', assetCode: 'EUR' },
    rawLabel: 'Virement',
    displayLabel: 'Virement',
  },
];

const worked: BulkEditPreview = {
  eligibleCount: 2,
  skipped: [{ id: C, reason: 'TRANSFER_LEG' }],
  impacts: [
    {
      accountId: 'a1',
      label: 'Courant',
      assetCode: 'EUR',
      delta: '60.00',
      from: '2026-10-03',
      to: null,
    },
    {
      accountId: 'a2',
      label: 'Joint',
      assetCode: 'EUR',
      delta: '-60.00',
      from: '2026-10-03',
      to: null,
    },
  ],
  previewToken: 'token',
};

function renderPreview(props: Partial<React.ComponentProps<typeof BulkMovePreview>> = {}) {
  const onBack = vi.fn();
  const onConfirm = vi.fn();
  render(
    <BulkMovePreview
      error={null}
      notice={null}
      onBack={onBack}
      onConfirm={onConfirm}
      pageItems={items}
      pending={false}
      preview={worked}
      {...props}
    />,
  );
  return { onBack, onConfirm };
}

describe('BulkMovePreview', () => {
  afterEach(cleanup);

  it('states the count, one signed line per impacted account and the skipped reason', () => {
    renderPreview();

    expect(screen.getByText('2 transactions seront déplacées.')).toBeTruthy();
    const impacts = screen.getByRole('list', { name: 'Effet sur les soldes' });
    const lines = within(impacts)
      .getAllByRole('listitem')
      .map((li) => li.textContent?.replace(/\s/g, ' '));
    expect(lines).toEqual([
      'Courant : +60,00 € à partir du 3 octobre 2026',
      'Joint : -60,00 € à partir du 3 octobre 2026',
    ]);
    expect(screen.getByText('Jambe de virement : modifiez le virement')).toBeTruthy();
  });

  it('shows a zero delta without a sign', () => {
    renderPreview({
      preview: {
        ...worked,
        impacts: [{ ...worked.impacts[0]!, delta: '0.000' }],
      },
    });

    const line = screen.getByRole('list', { name: 'Effet sur les soldes' }).textContent ?? '';
    expect(line.replace(/\s/g, ' ')).toBe('Courant : 0,00 € à partir du 3 octobre 2026');
  });

  it('states a date-only impact as an unchanged balance between two days', () => {
    renderPreview({
      preview: {
        ...worked,
        impacts: [
          {
            accountId: 'a1',
            label: 'Courant',
            assetCode: 'EUR',
            delta: '0',
            from: '2026-10-03',
            to: '2026-10-20',
          },
        ],
      },
    });

    expect(
      screen.getByText(
        'Courant : Solde inchangé à terme, modifié entre le 3 octobre 2026 et le 20 octobre 2026',
      ),
    ).toBeTruthy();
  });

  it('confirms and goes back', () => {
    const { onBack, onConfirm } = renderPreview();

    fireEvent.click(screen.getByRole('button', { name: 'Confirmer' }));
    fireEvent.click(screen.getByRole('button', { name: 'Retour' }));

    expect(onConfirm).toHaveBeenCalledTimes(1);
    expect(onBack).toHaveBeenCalledTimes(1);
  });

  it('disables Confirmer when nothing is eligible but still lists the skipped rows', () => {
    renderPreview({ preview: { ...worked, eligibleCount: 0, impacts: [] } });

    expect((screen.getByRole('button', { name: 'Confirmer' }) as HTMLButtonElement).disabled).toBe(
      true,
    );
    expect(screen.getByText('Jambe de virement : modifiez le virement')).toBeTruthy();
  });

  it('shows a skeleton with the real static chrome while the preview loads', () => {
    renderPreview({ preview: null });

    expect(document.querySelectorAll('[data-skeleton]').length).toBeGreaterThan(0);
    expect(screen.queryByText(/seront déplacées/)).toBeNull();
    expect((screen.getByRole('button', { name: 'Confirmer' }) as HTMLButtonElement).disabled).toBe(
      true,
    );
    expect(screen.getByRole('button', { name: 'Retour' })).toBeTruthy();
  });

  it('shows the refresh notice and an error alert', () => {
    renderPreview({
      notice: 'Les transactions ont changé : voici l’aperçu actualisé.',
      error: { kind: 'network', actualCount: null } as never,
    });

    expect(
      screen.getByText('Les transactions ont changé : voici l’aperçu actualisé.'),
    ).toBeTruthy();
    expect(screen.getByRole('alert').textContent).toContain('réessayez');
  });
});
