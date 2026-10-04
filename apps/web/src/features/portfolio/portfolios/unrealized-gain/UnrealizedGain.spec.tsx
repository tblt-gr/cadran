import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it } from 'vitest';
import '@/i18n';
import { UnrealizedGain } from './UnrealizedGain';

afterEach(cleanup);

describe('UnrealizedGain', () => {
  it('shows a signed amount and the performance with two decimals', () => {
    render(<UnrealizedGain assetCode="EUR" performance="0.073189" value="219.5" />);
    expect(screen.getByText(/\+219,50/)).toBeTruthy();
    expect(screen.getByText('+7,32 %')).toBeTruthy();
  });

  it('keeps the minus sign of a loss, never colour alone', () => {
    render(<UnrealizedGain assetCode="EUR" performance="-0.0909" value="-100" />);
    expect(screen.getByText(/-100,00/)).toBeTruthy();
    expect(screen.getByText('-9,09 %')).toBeTruthy();
  });

  it('shows a dash with the reason as hidden text when not calculable', () => {
    render(
      <UnrealizedGain assetCode="EUR" performance={null} reason="PARTIAL_VALUATION" value={null} />,
    );
    expect(screen.getAllByText('-').length).toBeGreaterThan(0);
    expect(screen.getAllByText('Valorisation partielle').length).toBeGreaterThan(0);
    expect(screen.queryByText('0,00 %')).toBeNull();
  });

  it('dashes only the performance when the cost is zero', () => {
    render(<UnrealizedGain assetCode="EUR" performance={null} value="10" />);
    expect(screen.getByText(/\+10,00/)).toBeTruthy();
    expect(screen.getByText('Performance non calculable')).toBeTruthy();
  });

  it('prints no sign when the figures round to zero', () => {
    render(<UnrealizedGain assetCode="EUR" performance="0.00004" value="0.004" />);
    expect(screen.getByText(/^0,00\s€$/)).toBeTruthy();
    expect(screen.getByText('0,00 %')).toBeTruthy();
    render(<UnrealizedGain assetCode="EUR" performance="-0.00004" value="-0.004" />);
    expect(screen.queryByText(/[+-]0,00/)).toBeNull();
  });
});
