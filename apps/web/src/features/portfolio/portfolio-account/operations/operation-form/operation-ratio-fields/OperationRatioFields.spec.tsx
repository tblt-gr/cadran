import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { OperationRatioFields } from './OperationRatioFields';

afterEach(cleanup);

describe('OperationRatioFields', () => {
  it('reports each side of the ratio and shows its error', () => {
    const onNumeratorChange = vi.fn();
    const onDenominatorChange = vi.fn();
    render(
      <OperationRatioFields
        denominator="1"
        error="Ratio invalide"
        numerator="2"
        onDenominatorChange={onDenominatorChange}
        onNumeratorChange={onNumeratorChange}
      />,
    );
    fireEvent.change(screen.getByLabelText('Numérateur du ratio'), { target: { value: '3' } });
    fireEvent.change(screen.getByLabelText('Dénominateur du ratio'), { target: { value: '4' } });
    expect(onNumeratorChange).toHaveBeenCalledWith('3');
    expect(onDenominatorChange).toHaveBeenCalledWith('4');
    expect(screen.getByRole('alert').textContent).toBe('Ratio invalide');
  });
});
