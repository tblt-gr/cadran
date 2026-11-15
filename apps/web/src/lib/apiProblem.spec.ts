import { describe, expect, it } from 'vitest';

import { classifyProblem } from './apiProblem';

describe('classifyProblem', () => {
  it('lets a feature type win over the stale version and the status', () => {
    const specific = { '/problems/taken': 'taken', '/problems/stale-version': 'custom' } as const;

    expect(classifyProblem(409, '/problems/taken', specific)).toBe('taken');
    expect(classifyProblem(401, '/problems/taken', specific)).toBe('taken');
    expect(classifyProblem(409, '/problems/stale-version', specific)).toBe('custom');
  });

  it('classifies the shared stale version type whatever the status', () => {
    expect(classifyProblem(409, '/problems/stale-version', {})).toBe('stale');
    expect(classifyProblem(412, '/problems/stale-version', {})).toBe('stale');
  });

  it.each([
    [401, 'unauthorized'],
    [403, 'unauthorized'],
    [409, 'conflict'],
    [400, 'invalid'],
    [422, 'invalid'],
    [404, 'network'],
    [500, 'network'],
    [0, 'network'],
  ])('falls back on status %i to %s', (status, kind) => {
    expect(classifyProblem(status, undefined, {})).toBe(kind);
    expect(classifyProblem(status, '/problems/unknown', {})).toBe(kind);
  });

  it('does not read inherited object keys as feature types', () => {
    expect(classifyProblem(500, 'constructor', {})).toBe('network');
  });
});
