import { describe, expect, it } from 'vitest';
import { performanceFixture } from './performanceFixtures';
import { gainFormulaText } from './performanceFormulas';

describe('gainFormulaText', () => {
  it('writes every term of a period with a start value', () => {
    const { gain } = performanceFixture();
    expect(gainFormulaText(gain, 'EUR')).toMatch(/^Gain = .+ − .+ − .+ = .+$/);
    expect(gainFormulaText(gain, 'EUR')).not.toContain('−  -');
  });

  it('leaves out the missing start value of a since-opening gain instead of a dash', () => {
    const { gain } = performanceFixture();
    const text = gainFormulaText({ ...gain, inputs: { ...gain.inputs, startValue: null } }, 'EUR');
    expect(text).not.toContain('- −');
    expect(text.match(/−/g)).toHaveLength(1);
  });

  it('keeps the dash when the gain itself is not computable', () => {
    const { gain } = performanceFixture();
    const text = gainFormulaText(
      { ...gain, value: null, inputs: { ...gain.inputs, startValue: null } },
      'EUR',
    );
    expect(text).toContain('− - −');
  });
});
