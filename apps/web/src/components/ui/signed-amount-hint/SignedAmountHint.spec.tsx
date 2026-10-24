import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { SignedAmountHint } from './SignedAmountHint';

afterEach(cleanup);

function hint(typed: string, direction: 'INFLOW' | 'OUTFLOW' | null) {
  render(<SignedAmountHint assetCode="EUR" direction={direction} id="hint" typed={typed} />);
  return screen.getByText(/.+/).textContent ?? '';
}

describe('SignedAmountHint', () => {
  it('names an unsigned expense as an outflow with its sign', () => {
    const text = hint('42.10', 'OUTFLOW');
    expect(text).toContain('Sortie');
    expect(text).toMatch(/-42,10/);
  });

  it('shows the same outcome when the minus was typed', () => {
    expect(hint('-42.10', 'OUTFLOW')).toMatch(/-42,10/);
  });

  it('names an income as an inflow', () => {
    expect(hint('42.10', 'INFLOW')).toContain('Entrée');
  });

  it('explains the convention while the entry is blank, zero or half typed', () => {
    expect(hint('', 'OUTFLOW')).toContain('sortie');
    cleanup();
    expect(hint('0', 'OUTFLOW')).toContain('sortie');
    cleanup();
    expect(hint('4,', null)).toContain('signe');
  });

  it('keeps the typed sign when no direction is known', () => {
    expect(hint('42.10', null)).toContain('Entrée');
  });
});
