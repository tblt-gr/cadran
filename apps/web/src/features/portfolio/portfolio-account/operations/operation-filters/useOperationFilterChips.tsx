import { readInstrument } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import type { FilterChip } from '@/features/transactions/transaction-filters/active-filter-chips/ActiveFilterChips';
import { formatCalendarNumericDay } from '@/lib/decimal';
import { operationTypeLabel } from '@/features/portfolio/portfolio-account/operations/operation-type-label';
import type { OperationFilterValues } from './operationFilterValues';

/** The name of the filtered instrument: the one the picker chose, else read from its id. */
function useInstrumentName(filters: OperationFilterValues) {
  const { t } = useTranslation();
  const id = filters.instrumentId;
  const lookup = useQuery({
    queryKey: ['operation-filter-instrument', id],
    enabled: id !== null && filters.instrumentName === null,
    queryFn: async ({ signal }) => {
      const result = await readInstrument({ ...authApiOptions(), path: { id: id! }, signal });
      if (!result.response?.ok || !result.data) throw new Error('Instrument lookup failed');
      return result.data.name;
    },
    retry: false,
  });
  return filters.instrumentName ?? lookup.data ?? t('portfolio.account.form.instrument');
}

/** One chip per applied filter; the voided toggle lives in the page header and has no chip. */
export function useOperationFilterChips(
  filters: OperationFilterValues,
  onChange: (next: OperationFilterValues) => void,
): FilterChip[] {
  const { t } = useTranslation();
  const instrumentName = useInstrumentName(filters);
  const clear = (label: string) => t('transactions.filters.clearChip', { label });
  const chips: FilterChip[] = [];

  for (const type of filters.types) {
    const label = operationTypeLabel(t, type);
    chips.push({
      key: `type-${type}`,
      label,
      removeLabel: clear(label),
      onClear: () => onChange({ ...filters, types: filters.types.filter((item) => item !== type) }),
    });
  }
  if (filters.from || filters.to) {
    const label =
      filters.from && filters.to
        ? t('portfolio.account.filters.period', {
            from: formatCalendarNumericDay(filters.from),
            to: formatCalendarNumericDay(filters.to),
          })
        : filters.from
          ? t('portfolio.account.filters.since', { date: formatCalendarNumericDay(filters.from) })
          : t('portfolio.account.filters.until', { date: formatCalendarNumericDay(filters.to) });
    chips.push({
      key: 'period',
      label,
      removeLabel: clear(label),
      onClear: () => onChange({ ...filters, from: '', to: '' }),
    });
  }
  if (filters.instrumentId !== null) {
    chips.push({
      key: 'instrument',
      label: instrumentName,
      removeLabel: clear(instrumentName),
      onClear: () => onChange({ ...filters, instrumentId: null, instrumentName: null }),
    });
  }
  return chips;
}
