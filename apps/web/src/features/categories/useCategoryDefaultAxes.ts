import { listCategories, type AnalyticAxes } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';

/**
 * The default axes of a category. A form editing a saved transaction knows the category's id
 * and label but not its axes, so they are looked up by label when `known` is missing.
 */
export function useCategoryDefaultAxes(
  categoryId: string,
  label: string | null,
  known: AnalyticAxes | null,
): AnalyticAxes | null {
  const lookup = useQuery({
    enabled: known === null && categoryId !== '' && label !== null,
    queryKey: ['category-default-axes', categoryId, label],
    queryFn: async ({ signal }) => {
      const result = await listCategories({
        ...authApiOptions(),
        query: { includeArchived: true, search: label ?? undefined, page: 1, pageSize: 50 },
        signal,
      });
      if (!result.response?.ok || !result.data) throw new Error('Unable to load category axes.');
      return (
        result.data.items.find((category) => category.id === categoryId)?.defaultAnalyticAxes ??
        null
      );
    },
    retry: false,
  });

  return known ?? lookup.data ?? null;
}
