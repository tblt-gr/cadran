import { cleanup, fireEvent, render, screen, within } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { CategoryAxesField } from './CategoryAxesField';

afterEach(cleanup);

describe('CategoryAxesField', () => {
  it('groups the axes by the question they answer', () => {
    render(<CategoryAxesField onToggle={vi.fn()} value={['FIXED']} />);

    for (const [group, axes] of [
      ['Besoin', ['Essentiel', 'Discrétionnaire']],
      ['Régularité', ['Fixe', 'Variable']],
      ['Usage', ['Personnel', 'Professionnel']],
    ] as const) {
      const column = screen.getByText(group).parentElement as HTMLElement;
      expect(within(column).getAllByRole('checkbox')).toHaveLength(2);
      for (const axis of axes) expect(within(column).getByLabelText(axis)).toBeTruthy();
    }
    expect((screen.getByLabelText('Fixe') as HTMLInputElement).checked).toBe(true);
  });

  it('reports the toggled axis', () => {
    const onToggle = vi.fn();
    render(<CategoryAxesField onToggle={onToggle} value={[]} />);

    fireEvent.click(screen.getByLabelText('Personnel'));

    expect(onToggle).toHaveBeenCalledWith('PERSONAL');
  });
});
