import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { FilterSection } from './FilterSection';

afterEach(cleanup);

describe('FilterSection', () => {
  it('names its region after its title and renders its filters', () => {
    render(
      <FilterSection title="Sens et montants">
        <input aria-label="Montant min" />
      </FilterSection>,
    );

    const region = screen.getByRole('region', { name: 'Sens et montants' });
    expect(region.querySelector('h3')?.textContent).toBe('Sens et montants');
    expect(region.contains(screen.getByLabelText('Montant min'))).toBe(true);
  });
});
