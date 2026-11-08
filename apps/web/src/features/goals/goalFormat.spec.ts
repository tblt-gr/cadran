import { describe, expect, it } from 'vitest';
import { formatEvaluationFigure } from './goalFormat';

describe('formatEvaluationFigure', () => {
  it('shows a ratio as the matching percentage without float drift', () => {
    expect(formatEvaluationFigure({ ratio: '0.20', percentDisplay: '20 %' }, 'fr-FR')).toBe(
      '20,00 %',
    );
  });

  it('shows an amount in the goal asset, exact to the cent and very large', () => {
    expect(formatEvaluationFigure({ value: '400.00', assetCode: 'EUR' }, 'fr-FR')).toMatch(
      /400,00/,
    );
    expect(
      formatEvaluationFigure({ value: '9007199254740993.01', assetCode: 'EUR' }, 'fr-FR'),
    ).toMatch(/9\s?007\s?199\s?254\s?740\s?993,01/);
  });

  it('formats zero with the shared amount formatter and keeps an absent figure as null', () => {
    expect(formatEvaluationFigure({ value: '0.00', assetCode: 'EUR' }, 'fr-FR')).not.toBeNull();
    expect(formatEvaluationFigure(null, 'fr-FR')).toBeNull();
    expect(formatEvaluationFigure({ value: null, assetCode: 'EUR' }, 'fr-FR')).toBeNull();
    expect(formatEvaluationFigure({ ratio: null, percentDisplay: '' }, 'fr-FR')).toBeNull();
  });
});
