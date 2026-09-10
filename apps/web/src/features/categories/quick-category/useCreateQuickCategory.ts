import {
  createCategory,
  type Category,
  type CategoryPage,
  type CreateCategoryRequest,
} from '@cadran/api-client';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { authApiOptions } from '@/features/auth/apiOptions';
import { withCsrfRetry } from '@/features/auth/withCsrfRetry';
import { categoryErrorKind, categoryRequestError } from '@/features/categories/categoryError';

export type QuickCategoryInput = Pick<CreateCategoryRequest, 'label' | 'parentId' | 'type'>;

export function useCreateQuickCategory() {
  const queryClient = useQueryClient();
  const mutation = useMutation({
    mutationFn: async (input: QuickCategoryInput) => {
      const body: CreateCategoryRequest = {
        ...input,
        icon: null,
        color: null,
        defaultAnalyticAxes: [],
        budgetIncluded: true,
        sortOrder: 0,
      };
      const result = await withCsrfRetry(() => createCategory({ ...authApiOptions(), body }));

      if (!result.response?.ok || !result.data) {
        throw categoryRequestError(result);
      }

      return result.data;
    },
    onSuccess: (category) => {
      writeCategoryToOpenQueries(queryClient, category);
      void Promise.all([
        queryClient.invalidateQueries({ queryKey: ['categories'] }),
        queryClient.invalidateQueries({ queryKey: ['category-candidates'] }),
      ]);
    },
  });

  return { ...mutation, errorKind: categoryErrorKind(mutation.error, mutation.isError) };
}

function writeCategoryToOpenQueries(
  queryClient: ReturnType<typeof useQueryClient>,
  category: Category,
) {
  // Only the first page of the categories screen takes the row: later pages would lose
  // their last one and shift, and the invalidation that follows reorders them anyway.
  queryClient.setQueriesData<CategoryPage>(
    { queryKey: ['categories'], predicate: (query) => query.queryKey[2] === 1 },
    (current) => addCategory(current, category),
  );

  const candidateQueries = queryClient
    .getQueryCache()
    .findAll({ queryKey: ['category-candidates'] });
  for (const query of candidateQueries) {
    const [, type, parentEligible, search] = query.queryKey;
    if (
      type !== category.type ||
      (parentEligible === true && !category.canAcceptChildren) ||
      (typeof search === 'string' &&
        search !== '' &&
        !normalize(category.label).includes(normalize(search)))
    ) {
      continue;
    }

    queryClient.setQueryData<CategoryPage>(query.queryKey, (current) =>
      addCategory(current, category),
    );
  }
}

function addCategory(current: CategoryPage | undefined, category: Category) {
  if (!current || current.items.some((candidate) => candidate.id === category.id)) return current;

  return {
    ...current,
    items: [category, ...current.items].slice(0, current.perPage),
    total: current.total + 1,
  };
}

function normalize(value: string): string {
  return value.normalize('NFKD').replace(/\p{M}/gu, '').toLocaleLowerCase('fr');
}
