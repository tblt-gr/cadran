import { useMediaQuery } from '@/hooks/use-media-query';
import { OperationFilterInputs, type OperationFiltersProps } from './OperationFilterInputs';
import { CompactOperationFilters } from './compact-operation-filters/CompactOperationFilters';

/** Up to 767 px the inputs hide behind the "Filtrer" button, like the transaction filters. */
const COMPACT_QUERY = '(max-width: 47.999rem)';

export function OperationFilters(props: OperationFiltersProps) {
  const compact = useMediaQuery(COMPACT_QUERY);
  return compact ? <CompactOperationFilters {...props} /> : <OperationFilterInputs {...props} />;
}
