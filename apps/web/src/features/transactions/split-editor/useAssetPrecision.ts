import { listAssets } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

/**
 * The display precision of one asset, read from the system reference so the
 * even-split helper never hard-codes a scale. `null` while the reference has
 * not loaded or does not know the code, which disables the helper rather
 * than guessing a scale.
 */
export function useAssetPrecision(assetCode: string): number | null {
  const assets = useQuery({
    queryKey: ['reference-assets'],
    queryFn: async ({ signal }) => {
      const result = await listAssets({
        ...authApiOptions(),
        query: { page: 1, perPage: 100 },
        signal,
      });
      if (!result.response?.ok || !result.data) {
        throw new Error('Unable to load the asset reference.');
      }
      return result.data;
    },
    retry: false,
  });

  return assets.data?.items.find((asset) => asset.code === assetCode)?.displayPrecision ?? null;
}
