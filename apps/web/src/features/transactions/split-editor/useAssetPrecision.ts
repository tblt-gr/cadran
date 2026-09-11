import { useReferenceAssets } from '@/hooks/use-reference-assets';

/**
 * The display precision of one asset, read from the system reference so the
 * even-split helper never hard-codes a scale. `null` while the reference has
 * not loaded or does not know the code, which disables the helper rather
 * than guessing a scale.
 */
export function useAssetPrecision(assetCode: string): number | null {
  const assets = useReferenceAssets();

  return assets.data?.items.find((asset) => asset.code === assetCode)?.displayPrecision ?? null;
}
