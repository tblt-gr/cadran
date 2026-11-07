import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { InstrumentPrecisionField } from './InstrumentPrecisionField';

afterEach(cleanup);

function renderField(props: Partial<Parameters<typeof InstrumentPrecisionField>[0]> = {}) {
  const onChange = vi.fn();
  render(
    <InstrumentPrecisionField
      label="Précision des prix"
      locked={false}
      onChange={onChange}
      showErrors={false}
      value="4"
      {...props}
    />,
  );
  return onChange;
}

describe('InstrumentPrecisionField', () => {
  it('reports typed values', () => {
    const onChange = renderField();
    fireEvent.change(screen.getByLabelText(/Précision des prix/), { target: { value: '5' } });
    expect(onChange).toHaveBeenCalledWith('5');
  });

  it('shows the range message only once errors are shown and the value is invalid', () => {
    renderField({ value: '13', showErrors: true });
    expect(screen.getByText('La précision doit être comprise entre 0 et 12.')).toBeTruthy();
  });

  it('stays quiet for a valid value', () => {
    renderField({ showErrors: true });
    expect(screen.queryByText(/comprise entre/)).toBeNull();
  });

  it('is frozen with a hint when locked', () => {
    renderField({ locked: true });
    expect((screen.getByLabelText(/Précision des prix/) as HTMLInputElement).disabled).toBe(true);
    expect(screen.getByText('Figé après la première utilisation')).toBeTruthy();
  });
});
