import type { PortfolioOperationType } from '@cadran/api-client';

export interface OperationFilterValues {
  from: string;
  includeVoided: boolean;
  instrumentId: string | null;
  to: string;
  types: PortfolioOperationType[];
}

export const noFilters: OperationFilterValues = {
  from: '',
  includeVoided: false,
  instrumentId: null,
  to: '',
  types: [],
};
