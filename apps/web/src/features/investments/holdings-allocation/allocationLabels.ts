import {
  instrumentClasses,
  instrumentRegions,
} from '@/features/investments/instruments/instrument-form/instrumentOptions';

export type AllocationCriterion = 'byAssetClass' | 'byRegion' | 'byInstrument';

const names: Record<AllocationCriterion, ReadonlyMap<string, string>> = {
  byAssetClass: new Map(instrumentClasses),
  byRegion: new Map(instrumentRegions),
  byInstrument: new Map(),
};

/** The server groups by code; classes and regions read with the names used on the instruments. */
export function sliceLabel(
  criterion: AllocationCriterion,
  slice: { key: string; label: string },
  unspecified: string,
): string {
  if (slice.key === 'UNSPECIFIED') return unspecified;
  return names[criterion].get(slice.key) ?? slice.label;
}
