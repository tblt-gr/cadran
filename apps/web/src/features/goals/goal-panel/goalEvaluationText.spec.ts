import { describe, expect, it } from 'vitest';
import i18n from '@/i18n';
import { goalEvaluation, rateEvaluation } from '@/features/goals/goal-mark/goalEvaluationFixtures';
import { toleranceText } from './goalEvaluationText';

describe('toleranceText', () => {
  it('shows a configured margin even when the achieved value is outside it', () => {
    expect(
      toleranceText(
        goalEvaluation({ tolerance: { value: '50', assetCode: 'EUR' }, withinTolerance: false }),
        i18n.t,
        'fr',
      ),
    ).toMatch(/Tolérance de 50,00\s€/);
  });
  it('expresses ratio margins in points and hides a strict margin', () => {
    expect(
      toleranceText(
        rateEvaluation({ tolerance: { ratio: '0.01', percentDisplay: '1 %' } }),
        i18n.t,
        'fr',
      ),
    ).toBe('Tolérance de 1,00 points');
    expect(toleranceText(goalEvaluation(), i18n.t, 'fr')).toBeNull();
  });
});
