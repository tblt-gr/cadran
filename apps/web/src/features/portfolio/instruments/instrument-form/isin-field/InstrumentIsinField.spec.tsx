import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { InstrumentIsinField } from './InstrumentIsinField';

afterEach(cleanup);

function renderField(props: Partial<Parameters<typeof InstrumentIsinField>[0]> = {}) {
  const onChange = vi.fn();
  render(
    <InstrumentIsinField locked={false} onChange={onChange} value="FR0000000001" {...props} />,
  );
  return onChange;
}

describe('InstrumentIsinField', () => {
  it('upper-cases typed values', () => {
    const onChange = renderField();
    fireEvent.change(screen.getByLabelText(/ISIN/), { target: { value: 'fr12' } });
    expect(onChange).toHaveBeenCalledWith('FR12');
  });

  it('links to the existing instrument on a duplicate', () => {
    renderField({ existingInstrumentId: 'i1' });
    expect(
      screen.getByRole('link', { name: 'Voir l’instrument existant' }).getAttribute('href'),
    ).toBe('/portfolios/instruments?q=FR0000000001&status=all');
    expect(screen.getByLabelText(/ISIN/).getAttribute('aria-invalid')).toBe('true');
  });

  it('shows a server error, then the frozen hint when locked', () => {
    renderField({ error: 'ISIN invalide', locked: true });
    expect(screen.getByText('ISIN invalide')).toBeTruthy();
  });

  it('shows the frozen hint when locked without error', () => {
    renderField({ locked: true });
    expect(screen.getByText('Figé après la première opération')).toBeTruthy();
  });
});
