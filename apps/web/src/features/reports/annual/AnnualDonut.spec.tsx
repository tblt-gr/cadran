import { cleanup, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { AnnualDonut } from './AnnualDonut';

afterEach(() => {
  cleanup();
  vi.restoreAllMocks();
});

function items(count: number) {
  return Array.from({ length: count }, (_, index) => ({
    amount: `${index + 1}0,00`,
    key: `c${index}`,
    label: `Catégorie ${index}`,
    share: '0.1',
  }));
}

describe('AnnualDonut', () => {
  it('lists every category with no summed "Autres" row', () => {
    render(<AnnualDonut assetCode="EUR" label="Diagramme" legend={items(3)} slices={items(3)} />);

    const list = screen.getByRole('list', { name: 'Diagramme' });
    const rows = within(list).getAllByRole('listitem');
    expect(rows).toHaveLength(3);
    expect(rows.map((row) => row.textContent)).toEqual([
      expect.stringContaining('Catégorie 0'),
      expect.stringContaining('Catégorie 1'),
      expect.stringContaining('Catégorie 2'),
    ]);
    expect(screen.queryByText('Autres')).toBeNull();
  });

  it('draws one distinct-coloured slice per category, cycling the palette with no two adjacent slices sharing a colour', async () => {
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockReturnValue({
      bottom: 0,
      height: 200,
      left: 0,
      right: 0,
      top: 0,
      width: 200,
      x: 0,
      y: 0,
      toJSON: () => ({}),
    });
    const eight = items(8);
    const { container } = render(
      <AnnualDonut assetCode="EUR" label="Diagramme" legend={eight} slices={eight} />,
    );

    // The chart library loads on demand, after the box is measured.
    await waitFor(
      () => expect(container.querySelectorAll('path.recharts-sector')).toHaveLength(8),
      {
        timeout: 5000,
      },
    );
    const sectors = container.querySelectorAll('path.recharts-sector');
    const fills = Array.from(sectors).map((sector) => sector.getAttribute('fill'));
    const distinctColours = new Set(fills);
    expect(distinctColours.size).toBeGreaterThan(1);
    for (let index = 0; index < fills.length; index += 1) {
      const next = fills[(index + 1) % fills.length];
      expect(fills[index]).not.toBe(next);
    }
  });

  it('collapses the legend behind Voir plus / Voir moins', () => {
    const nine = items(9);
    render(
      <AnnualDonut assetCode="EUR" label="Diagramme" legend={nine} slices={nine} visibleRows={6} />,
    );

    const list = screen.getByRole('list', { name: 'Diagramme' });
    expect(within(list).getAllByRole('listitem')).toHaveLength(6);
    const toggle = screen.getByRole('button', { name: 'Voir plus' });
    fireEvent.click(toggle);
    expect(within(list).getAllByRole('listitem')).toHaveLength(9);
    expect(toggle.textContent).toBe('Voir moins');
  });
});
