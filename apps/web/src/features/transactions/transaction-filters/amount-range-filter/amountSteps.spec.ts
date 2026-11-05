import { describe, expect, it } from 'vitest';
import {
  LAST_POSITION,
  maxBoundAt,
  minBoundAt,
  positionForMax,
  positionForMin,
} from './amountSteps';

describe('amountSteps', () => {
  it('maps the ends of the track to "no bound"', () => {
    expect(minBoundAt(0)).toBe('');
    expect(maxBoundAt(LAST_POSITION)).toBe('');
    expect(positionForMin('')).toBe(0);
    expect(positionForMax('')).toBe(LAST_POSITION);
  });

  it('maps inner positions to the thresholds and back', () => {
    expect(minBoundAt(2)).toBe('10');
    expect(maxBoundAt(4)).toBe('50');
    expect(maxBoundAt(0)).toBe('0');
    expect(positionForMin('10')).toBe(2);
    expect(positionForMax('50')).toBe(4);
  });

  it('never lets a thumb claim a wider range than the typed bound', () => {
    expect(positionForMin('12.50')).toBe(2);
    expect(positionForMin('3')).toBe(0);
    expect(positionForMax('12.50')).toBe(3);
    expect(positionForMax('25000')).toBe(LAST_POSITION);
  });

  it('leaves the thumbs at rest for a bound that is not a canonical decimal yet', () => {
    expect(positionForMin('12.')).toBe(0);
    expect(positionForMax('007')).toBe(LAST_POSITION);
  });
});
