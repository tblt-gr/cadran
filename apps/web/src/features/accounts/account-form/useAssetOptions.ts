import { listAssets, type Asset } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

const PREFERRED_ASSET = 'EUR';

export interface AssetOptions {
  items: Asset[];
  /** The code the form will submit: the selection, or the default when it is not one of the loaded codes. */
  selected: string;
  isPending: boolean;
  isError: boolean;
}

/**
 * The denominations an account may be created in, read from the system asset
 * reference.
 *
 * The effective selection is derived during render rather than pushed into
 * state by an effect: the form must never submit a currency that is merely a
 * leftover default. When the reference cannot be read the selection is empty,
 * and the form refuses to create the account — the denomination is fixed at
 * creation and could not be corrected afterwards.
 */
export function useAssetOptions(selection: string): AssetOptions {
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

  const items = assets.data?.items ?? [];
  const codes = items.map((asset) => asset.code);
  const fallback = codes.includes(PREFERRED_ASSET) ? PREFERRED_ASSET : (codes[0] ?? '');

  return {
    items,
    selected: codes.includes(selection) ? selection : fallback,
    isPending: assets.isPending,
    isError: assets.isError,
  };
}
