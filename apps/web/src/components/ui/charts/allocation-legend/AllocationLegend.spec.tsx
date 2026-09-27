import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { AllocationLegend } from './AllocationLegend';

afterEach(cleanup);

describe('AllocationLegend', () => {
  it('lists label, share and amount for each item with a decorative bar', () => {
    const { container } = render(
      <AllocationLegend
        items={[
          { amount: '30,00 €', barPercent: '30', key: 'a', label: 'Loyer', share: '30 %' },
          { amount: '-', barPercent: null, key: 'b', label: 'Autres', share: '-' },
        ]}
        label="Répartition"
      />,
    );

    const items = within(screen.getByRole('list', { name: 'Répartition' })).getAllByRole(
      'listitem',
    );
    expect(items).toHaveLength(2);
    expect(items[0]?.textContent).toContain('Loyer');
    expect(items[0]?.textContent).toContain('30,00 €');
    expect(container.querySelectorAll('[style*="width: 30%"]')).toHaveLength(1);
    expect(container.querySelectorAll('[aria-hidden="true"]')).toHaveLength(2);
  });

  it('collapses to the first rows behind an accessible toggle', () => {
    render(
      <AllocationLegend
        collapse={{ count: 1, lessLabel: 'Voir moins', moreLabel: 'Voir plus' }}
        items={[
          { amount: '1', barPercent: '1', key: 'a', label: 'A', share: '1' },
          { amount: '2', barPercent: '2', key: 'b', label: 'B', share: '2' },
        ]}
        label="Répartition"
      />,
    );
    const list = screen.getByRole('list', { name: 'Répartition' });
    expect(within(list).getAllByRole('listitem')).toHaveLength(1);
    const toggle = screen.getByRole('button', { name: 'Voir plus' });
    expect(toggle.getAttribute('aria-controls')).toBe(list.id);
    fireEvent.click(toggle);
    expect(within(list).getAllByRole('listitem')).toHaveLength(2);
    expect(toggle.getAttribute('aria-expanded')).toBe('true');
    expect(toggle.textContent).toBe('Voir moins');
  });
});
