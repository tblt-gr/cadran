import { describe, expect, it } from 'vitest';
import {
  emptyModelFormValues,
  missingCapabilityDependencies,
  modelFormProblems,
  type ModelFormValues,
} from './modelFormValues';

function values(overrides: Partial<ModelFormValues> = {}): ModelFormValues {
  return { ...emptyModelFormValues(), name: 'Livret Banque X', ...overrides };
}

describe('modelFormValues', () => {
  it('names the capabilities a selection rests on and does not hold', () => {
    expect(missingCapabilityDependencies(['SUPPORTS_TRANSACTIONS', 'SUPPORTS_INTEREST'])).toEqual([
      'SUPPORTS_BALANCE',
    ]);
    expect(missingCapabilityDependencies(['SUPPORTS_TRANSACTIONS', 'SUPPORTS_TRADES'])).toEqual([
      'SUPPORTS_BALANCE',
      'SUPPORTS_HOLDINGS',
    ]);
    expect(missingCapabilityDependencies(['SUPPORTS_BALANCE', 'SUPPORTS_TRANSACTIONS'])).toEqual(
      [],
    );
  });

  it('sends an unsupported combination back to the fieldset instead of to the server', () => {
    // Interest without a balance is what the domain refuses; caught here, the
    // answer points at a field rather than arriving as a generic 422.
    expect(
      modelFormProblems(values({ capabilities: ['SUPPORTS_TRANSACTIONS', 'SUPPORTS_INTEREST'] })),
    ).toContain('capabilities');
    expect(
      modelFormProblems(
        values({
          capabilities: ['SUPPORTS_BALANCE', 'SUPPORTS_TRANSACTIONS', 'SUPPORTS_INTEREST'],
        }),
      ),
    ).toEqual([]);
  });

  it('holds the liability capability to a liability family, which needs a balance', () => {
    expect(
      modelFormProblems(
        values({
          family: 'LIABILITY',
          valuationMode: 'SNAPSHOTS',
          capabilities: ['SUPPORTS_BALANCE', 'SUPPORTS_LIABILITY'],
        }),
      ),
    ).toEqual([]);
    expect(
      modelFormProblems(
        values({
          family: 'LIABILITY',
          capabilities: ['SUPPORTS_TRANSACTIONS', 'SUPPORTS_LIABILITY'],
        }),
      ),
    ).toContain('capabilities');
  });
});
