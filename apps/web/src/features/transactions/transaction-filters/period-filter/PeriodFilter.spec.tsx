import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { PeriodFilter } from './PeriodFilter';

describe('PeriodFilter', () => {
  afterEach(() => {
    cleanup();
  });

  it('hides the date range until the custom preset is picked', () => {
    render(<PeriodFilter from="" onChange={vi.fn()} period="all" to="" />);

    expect(screen.queryByText('Du')).toBeNull();

    render(<PeriodFilter from="" onChange={vi.fn()} period="custom" to="" />);

    expect(screen.getAllByText('Du').length).toBeGreaterThan(0);
  });

  it('reports the full triple on a preset switch and on each date edit', () => {
    const onChange = vi.fn();
    render(<PeriodFilter from="2026-01-01" onChange={onChange} period="custom" to="2026-01-31" />);

    fireEvent.change(screen.getByLabelText('Du'), { target: { value: '2026-02-01' } });
    expect(onChange).toHaveBeenLastCalledWith('custom', '2026-02-01', '2026-01-31');

    fireEvent.change(screen.getByLabelText('Au'), { target: { value: '2026-02-28' } });
    expect(onChange).toHaveBeenLastCalledWith('custom', '2026-01-01', '2026-02-28');
  });
});
