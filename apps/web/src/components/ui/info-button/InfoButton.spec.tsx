import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { InfoButton } from './InfoButton';

afterEach(cleanup);

describe('InfoButton', () => {
  it('is a named button that shows its label as a tooltip and forwards clicks', () => {
    const onClick = vi.fn();
    render(<InfoButton aria-expanded={false} label="Expliquer : Revenus" onClick={onClick} />);

    const button = screen.getByRole('button', { name: 'Expliquer : Revenus' });
    expect(button.getAttribute('title')).toBe('Expliquer : Revenus');
    expect(button.getAttribute('aria-expanded')).toBe('false');
    expect(button.getAttribute('type')).toBe('button');
    expect(button.classList.contains('icon-button')).toBe(true);
    fireEvent.click(button);
    expect(onClick).toHaveBeenCalledOnce();
  });
});
