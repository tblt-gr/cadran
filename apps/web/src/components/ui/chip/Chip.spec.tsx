import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { Chip } from './Chip';

describe('Chip', () => {
  afterEach(cleanup);

  it('shows its label and removes through one button named by removeLabel', () => {
    const onRemove = vi.fn();
    render(<Chip label="Sorties" onRemove={onRemove} removeLabel="Retirer le filtre Sorties" />);

    expect(screen.getByText('Sorties')).toBeTruthy();
    fireEvent.click(screen.getByRole('button', { name: 'Retirer le filtre Sorties' }));

    expect(onRemove).toHaveBeenCalledTimes(1);
  });

  it('accepts a node as label', () => {
    render(<Chip label={<em>Rich</em>} onRemove={vi.fn()} removeLabel="Retirer" />);

    expect(screen.getByText('Rich').tagName).toBe('EM');
  });
});
