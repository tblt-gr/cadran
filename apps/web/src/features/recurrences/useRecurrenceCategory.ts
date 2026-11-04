import type { Category } from '@cadran/api-client';
import { useCategoryIdentities } from '@/features/categories/useCategoryIdentities';

/**
 * The category a recurrence carries, with its archived siblings: a recurrence keeps pointing
 * at a category archived after it was saved, and the card must still name it.
 */
export function useRecurrenceCategory(categoryId: string | null): Category | undefined {
  const identities = useCategoryIdentities(categoryId !== null);

  return categoryId === null ? undefined : identities.data?.get(categoryId);
}
