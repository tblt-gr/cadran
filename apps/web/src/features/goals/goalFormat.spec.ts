import { describe, expect, it } from 'vitest';
import { formatGoalFigure } from './goalFormat';

describe('formatGoalFigure', () => {
  it('shows a rate as an exact percentage', () => {
    expect(formatGoalFigure('0.20', 'RATE', 'EUR', 'fr-FR')).toBe('20,00 %');
  });

  it('shows every other value type as an amount in the goal currency', () => {
    expect(formatGoalFigure('400.00', 'AMOUNT', 'EUR', 'fr-FR')).toMatch(/400,00/);
    expect(formatGoalFigure('400.00', 'INCOME_SHARE', 'EUR', 'fr-FR')).toMatch(/€/);
    expect(formatGoalFigure('400.00', null, 'EUR', 'fr-FR')).toMatch(/€/);
  });
});
