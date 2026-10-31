import type { TransactionNature } from '@cadran/api-client';

export interface AccountMovementFilters {
  from: string;
  to: string;
  includeVoided: boolean;
  nature: TransactionNature[];
}
export const emptyMovementFilters: AccountMovementFilters = {
  from: '',
  to: '',
  includeVoided: false,
  nature: [],
};

/** Inclusive `[from, to]` bound for one calendar month, or both null when unset. */
export function monthBounds(month: string | null): { from?: string; to?: string } {
  if (month === null || !/^\d{4}-\d{2}$/.test(month)) {
    return {};
  }

  const [year, monthNumber] = month.split('-').map(Number) as [number, number];
  const lastDay = new Date(year, monthNumber, 0).getDate();

  return {
    from: `${month}-01`,
    to: `${month}-${String(lastDay).padStart(2, '0')}`,
  };
}
