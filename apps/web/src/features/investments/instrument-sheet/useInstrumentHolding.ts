import { readInstrumentHolding } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { instrumentRequestError } from '@/features/investments/instruments/instrumentError';

export function useInstrumentHolding(id: string) {
  return useQuery({
    queryKey: ['instrument-holding', id],
    queryFn: async ({ signal }) => {
      const result = await readInstrumentHolding({ ...authApiOptions(), path: { id }, signal });
      if (!result.response?.ok || !result.data) throw instrumentRequestError(result);
      return result.data;
    },
    retry: false,
  });
}
