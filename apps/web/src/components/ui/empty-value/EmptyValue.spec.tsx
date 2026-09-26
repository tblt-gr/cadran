import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import { EmptyValue } from './EmptyValue';

afterEach(cleanup);

describe('EmptyValue', () => {
  it('shows only a dash and keeps the label and reason for assistive technology', () => {
    const { container } = render(<EmptyValue label="Non calculable" reason="Revenus nuls" />);

    const dash = container.querySelector('[aria-hidden="true"]');
    expect(dash?.textContent).toBe('-');
    expect(screen.getByText('Non calculable').className).toContain('sr-only');
    expect(screen.getByText('Revenus nuls').className).toContain('sr-only');
    expect(container.firstElementChild?.getAttribute('title')).toBe(
      'Non calculable : Revenus nuls',
    );
  });

  it('uses the label alone when there is no reason', () => {
    const { container } = render(<EmptyValue label="À venir" />);

    expect(container.firstElementChild?.getAttribute('title')).toBe('À venir');
  });
});
