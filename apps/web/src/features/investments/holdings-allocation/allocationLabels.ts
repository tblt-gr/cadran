import {
  instrumentClasses,
  instrumentRegions,
} from '@/features/investments/instruments/instrument-form/instrumentOptions';

export type AllocationCriterion = 'byAssetClass' | 'byRegion' | 'byInstrument' | 'byAccount';

const names: Record<AllocationCriterion, ReadonlyMap<string, string>> = {
  byAssetClass: new Map(instrumentClasses),
  byRegion: new Map(instrumentRegions),
  byInstrument: new Map(),
  byAccount: new Map(),
};

/** The server groups by code; classes and regions read with the names used on the instruments. */
export function sliceLabel(
  criterion: AllocationCriterion,
  slice: { key: string; label: string },
  unspecified: string,
): string {
  // Accounts valued as a total carry their own label from the server.
  if (slice.key === 'UNSPLIT') return slice.label;
  if (slice.key === 'UNSPECIFIED') return unspecified;
  return names[criterion].get(slice.key) ?? slice.label;
}
