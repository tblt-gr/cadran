import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { GroupingModeToggle } from './GroupingModeToggle';

afterEach(cleanup);

describe('GroupingModeToggle', () => {
  it('reflects the mode in aria-pressed and switches on click', () => {
    const onChange = vi.fn();
    render(<GroupingModeToggle mode="PRIMARY" onChange={onChange} />);
    const button = screen.getByRole('button', { name: 'Détailler par sous-groupe' });
    expect(button.getAttribute('aria-pressed')).toBe('true');
    fireEvent.click(button);
    expect(onChange).toHaveBeenCalledWith('PARENT');
  });

  it('is a focusable native button, so Enter and Space activate it', () => {
    render(<GroupingModeToggle mode="PARENT" onChange={vi.fn()} />);
    const button = screen.getByRole('button', { name: 'Détailler par sous-groupe' });
    expect(button.tagName).toBe('BUTTON');
    expect(button.getAttribute('type')).toBe('button');
    expect(button.getAttribute('aria-pressed')).toBe('false');
    button.focus();
    expect(document.activeElement).toBe(button);
  });

  it('is inert when disabled', () => {
    render(<GroupingModeToggle disabled mode="PRIMARY" onChange={vi.fn()} />);
    expect((screen.getByRole('button') as HTMLButtonElement).disabled).toBe(true);
  });
});
