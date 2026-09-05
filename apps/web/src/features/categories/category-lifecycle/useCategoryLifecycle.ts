import {
  archiveCategory,
  mergeCategory,
  moveCategory,
  replaceCategory,
  type Category,
  type CategoryLifecycleOperation,
} from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { CategoryRequestError, categoryRequestError } from '@/features/categories/categoryError';
import type { CategoryLifecycleConfirmation } from './CategoryLifecycleDialog';

interface LifecycleTarget {
  category: Category;
  operation: CategoryLifecycleOperation;
}

/**
 * Which lifecycle operation is being confirmed, and the write it leads to.
 *
 * Every operation sends the version the category carried when the dialog
 * opened, so a category changed elsewhere in the meantime is refused by the
 * backend instead of being reorganised against a stale preview.
 */
export function useCategoryLifecycle(onApplied: () => void) {
  const queryClient = useQueryClient();
  const [target, setTarget] = useState<LifecycleTarget | null>(null);

  const apply = useMutation({
    mutationFn: async ({ effectiveFrom, operation, targetId }: CategoryLifecycleConfirmation) => {
      if (!target) {
        throw new CategoryRequestError(0, undefined, []);
      }

      const options = { ...authApiOptions(), path: { id: target.category.id } };
      const version = target.category.version;
      const result = await withCsrfRetry(() => {
        switch (operation) {
          case 'ARCHIVE':
            return archiveCategory({ ...options, body: { version } });
          case 'MERGE':
            return mergeCategory({ ...options, body: { targetId: targetId ?? '', version } });
          case 'MOVE':
            return moveCategory({ ...options, body: { parentId: targetId, version } });
          case 'REPLACE':
            return replaceCategory({
              ...options,
              body: { effectiveFrom, targetId: targetId ?? '', version },
            });
        }
      });

      if (!result.response?.ok || !result.data) {
        throw categoryRequestError(result);
      }
      return result.data;
    },
    onSuccess: async () => {
      setTarget(null);
      onApplied();
      await queryClient.invalidateQueries({ queryKey: ['categories'] });
      await queryClient.invalidateQueries({ queryKey: ['category-impact'] });
      await queryClient.invalidateQueries({ queryKey: ['category-candidates'] });
    },
  });

  return {
    apply,
    close: () => {
      setTarget(null);
      apply.reset();
    },
    open: (category: Category, operation: CategoryLifecycleOperation) => {
      apply.reset();
      setTarget({ category, operation });
    },
    target,
  };
}
