import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { PeriodFilter } from './PeriodFilter';

const BASE = { from: '', month: '', to: '' };

describe('PeriodFilter', () => {
  afterEach(() => {
    cleanup();
  });

  it('hides the date range until the custom preset is picked', () => {
    render(<PeriodFilter {...BASE} onChange={vi.fn()} period="all" />);

    expect(screen.queryByText('Du')).toBeNull();

    render(<PeriodFilter {...BASE} onChange={vi.fn()} period="custom" />);

    expect(screen.getAllByText('Du').length).toBeGreaterThan(0);
  });

  it('offers a month field only for the month preset', () => {
    const { container } = render(<PeriodFilter {...BASE} onChange={vi.fn()} period="all" />);
    expect(container.querySelector('input[type="month"]')).toBeNull();

    cleanup();
    render(<PeriodFilter {...BASE} month="2026-11" onChange={vi.fn()} period="month" />);
    expect((screen.getByLabelText('Mois') as HTMLInputElement).value).toBe('2026-11');
  });

  it('seeds the month with the current one when the month preset is picked', () => {
    const onChange = vi.fn();
    render(<PeriodFilter {...BASE} onChange={onChange} period="all" />);

    fireEvent.click(screen.getByRole('radio', { name: 'Un mois' }));

    const next = onChange.mock.calls[0][0];
    expect(next.period).toBe('month');
    expect(next.month).toMatch(/^\d{4}-\d{2}$/);
  });

  it('reports the full value on each date edit and month edit', () => {
    const onChange = vi.fn();
    const { rerender } = render(
      <PeriodFilter
        from="2026-01-01"
        month=""
        onChange={onChange}
        period="custom"
        to="2026-01-31"
      />,
    );

    fireEvent.change(screen.getByLabelText('Du'), { target: { value: '2026-02-01' } });
    expect(onChange).toHaveBeenLastCalledWith({
      from: '2026-02-01',
      month: '',
      period: 'custom',
      to: '2026-01-31',
    });

    fireEvent.change(screen.getByLabelText('Au'), { target: { value: '2026-02-28' } });
    expect(onChange).toHaveBeenLastCalledWith({
      from: '2026-01-01',
      month: '',
      period: 'custom',
      to: '2026-02-28',
    });

    rerender(<PeriodFilter {...BASE} month="2026-11" onChange={onChange} period="month" />);
    fireEvent.change(screen.getByLabelText('Mois'), { target: { value: '2026-10' } });
    expect(onChange).toHaveBeenLastCalledWith({
      from: '',
      month: '2026-10',
      period: 'month',
      to: '',
    });
  });
});
