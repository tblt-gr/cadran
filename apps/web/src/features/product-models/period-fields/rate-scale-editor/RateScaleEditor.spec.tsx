import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import '@/i18n';
import { RateScaleEditor } from './RateScaleEditor';

afterEach(cleanup);

describe('RateScaleEditor', () => {
  it('offers the example-balance preview for a tiered scale', () => {
    render(
      <RateScaleEditor
        brackets={[
          { lowerBound: '0', upperBound: '10000', percentage: '4' },
          { lowerBound: '10000', upperBound: '', percentage: '2' },
        ]}
        invalid={false}
        onBracketsChange={vi.fn()}
        onRateApplicationChange={vi.fn()}
        onScaleShapeChange={vi.fn()}
        onSingleRateChange={vi.fn()}
        rateApplication="MARGINAL"
        scaleShape="tiered"
        singleRate=""
      />,
    );

    expect(screen.getByLabelText('Solde d’exemple')).toBeTruthy();
  });

  it('offers no example-balance preview for a single rate', () => {
    render(
      <RateScaleEditor
        brackets={[]}
        invalid={false}
        onBracketsChange={vi.fn()}
        onRateApplicationChange={vi.fn()}
        onScaleShapeChange={vi.fn()}
        onSingleRateChange={vi.fn()}
        rateApplication="MARGINAL"
        scaleShape="single"
        singleRate="4"
      />,
    );

    expect(screen.queryByLabelText('Solde d’exemple')).toBeNull();
  });
});
