import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { BusinessDateField } from './BusinessDateField';

describe('BusinessDateField', () => {
  afterEach(cleanup);

  it('reports the chosen business date and restores today', () => {
    const onChange = vi.fn();

    const { rerender } = render(
      <BusinessDateField onChange={onChange} today="2026-09-02" value="2026-08-21" />,
    );

    fireEvent.change(screen.getByLabelText('Date métier'), { target: { value: '2026-08-22' } });
    expect(onChange).toHaveBeenLastCalledWith('2026-08-22');

    fireEvent.click(screen.getByRole('button', { name: 'Aujourd’hui' }));
    expect(onChange).toHaveBeenLastCalledWith('2026-09-02');

    rerender(<BusinessDateField onChange={onChange} today="2026-09-02" value="2026-09-02" />);
    expect(
      (screen.getByRole('button', { name: 'Aujourd’hui' }) as HTMLButtonElement).disabled,
    ).toBe(true);
  });

  it('restores today when the native date input is cleared', () => {
    const onChange = vi.fn();

    render(<BusinessDateField onChange={onChange} today="2026-09-02" value="2026-08-21" />);

    fireEvent.change(screen.getByLabelText('Date métier'), { target: { value: '' } });

    expect(onChange).toHaveBeenCalledWith('2026-09-02');
    expect(onChange).not.toHaveBeenCalledWith('');
  });
});
