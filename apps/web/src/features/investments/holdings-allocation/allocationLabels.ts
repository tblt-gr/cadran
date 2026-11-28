import type { TFunction } from 'i18next';

export type AllocationCriterion = 'byAssetClass' | 'byRegion' | 'byInstrument' | 'byAccount';

const nameGroups: Partial<Record<AllocationCriterion, string>> = {
  byAssetClass: 'classes',
  byRegion: 'regions',
};

/** The server groups by code; classes and regions read with the names used on the instruments. */
export function sliceLabel(
  criterion: AllocationCriterion,
  slice: { key: string; label: string },
  unspecified: string,
  t: TFunction,
): string {
  if (slice.key === 'UNSPLIT') return t('investments.allocation.unsplit');
  if (slice.key === 'UNSPECIFIED') return unspecified;
  const group = nameGroups[criterion];
  return group
    ? t(`investments.instruments.${group}.${slice.key}`, { defaultValue: slice.label })
    : slice.label;
}
