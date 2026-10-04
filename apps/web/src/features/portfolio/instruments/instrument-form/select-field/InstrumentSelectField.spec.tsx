import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { InstrumentSelectField } from './InstrumentSelectField';

afterEach(cleanup);

const options = [
  ['A', 'Alpha'],
  ['B', 'Beta'],
] as const;

describe('InstrumentSelectField', () => {
  it('offers the options and reports the choice', () => {
    const onChange = vi.fn();
    render(<InstrumentSelectField label="Type" onChange={onChange} options={options} value="A" />);
    fireEvent.change(screen.getByLabelText('Type'), { target: { value: 'B' } });
    expect(onChange).toHaveBeenCalledWith('B');
    expect(screen.queryByText('Non renseignée')).toBeNull();
  });

  it('adds an empty choice when nullable', () => {
    render(
      <InstrumentSelectField
        label="Région"
        nullable
        onChange={vi.fn()}
        options={options}
        value=""
      />,
    );
    expect(screen.getByText('Non renseignée')).toBeTruthy();
  });
});
