import { describe, expect, it } from 'vitest';
import {
  addDecimals,
  compareDecimals,
  isCanonicalDecimal,
  negateDecimal,
  subtractDecimals,
  sumDecimals,
} from './decimal';
import { DecimalGenerator, propertyCases, propertySeed } from './testing/decimalGenerator';

interface Case {
  inputs: string[];
  check: () => void;
}

/** Runs a property on seeded cases; a failure names the seed, the case index and its inputs. */
function forAll(property: (generator: DecimalGenerator) => Case): void {
  const seed = propertySeed();
  const generator = new DecimalGenerator(seed);
  const cases = propertyCases();
  for (let index = 0; index < cases; index += 1) {
    const { inputs, check } = property(generator);
    try {
      check();
    } catch (error) {
      throw new Error(
        `Property failed (PROPERTY_SEED=${seed}, case ${index}, inputs ${JSON.stringify(inputs)}): ${String(error)}`,
        { cause: error },
      );
    }
  }
}

// Operands carry at most 25 integer digits so a sum of two or three stays within 26 digits.
describe('decimal properties on generated inputs', () => {
  it('subtracting b from a + b gives back a', () => {
    forAll((g) => {
      const a = g.decimal(25);
      const b = g.decimal(25);
      // Arithmetic keeps the wider scale, so the restored value equals a numerically.
      return {
        inputs: [a, b],
        check: () => expect(compareDecimals(subtractDecimals(addDecimals(a, b), b), a)).toBe(0),
      };
    });
  });

  it('a sum equals the pairwise addition', () => {
    forAll((g) => {
      const [a, b, c] = [g.decimal(25), g.decimal(25), g.decimal(25)];
      return {
        inputs: [a, b, c],
        check: () => expect(sumDecimals([a, b, c])).toBe(addDecimals(addDecimals(a, b), c)),
      };
    });
  });

  it('negation is an involution and generated values are canonical', () => {
    forAll((g) => {
      const a = g.decimal();
      return {
        inputs: [a],
        check: () => {
          expect(isCanonicalDecimal(a)).toBe(true);
          expect(negateDecimal(negateDecimal(a))).toBe(a);
        },
      };
    });
  });

  it('comparison is antisymmetric', () => {
    forAll((g) => {
      const [a, b] = [g.decimal(), g.decimal()];
      return {
        inputs: [a, b],
        check: () => expect(compareDecimals(a, b)).toBe(-compareDecimals(b, a)),
      };
    });
  });
});
