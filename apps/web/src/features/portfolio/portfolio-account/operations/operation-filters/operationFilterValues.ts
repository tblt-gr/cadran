import type { PortfolioOperationType } from '@cadran/api-client';

export interface OperationFilterValues {
  from: string;
  includeVoided: boolean;
  instrumentId: string | null;
  /** The picked instrument's name, for its chip; null when only the id is known. */
  instrumentName: string | null;
  to: string;
  types: PortfolioOperationType[];
}

export const noFilters: OperationFilterValues = {
  from: '',
  includeVoided: false,
  instrumentId: null,
  instrumentName: null,
  to: '',
  types: [],
};
