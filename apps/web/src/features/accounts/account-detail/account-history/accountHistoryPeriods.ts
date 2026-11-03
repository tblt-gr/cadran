import type { ChartPeriod } from '@/lib/chartPeriodRange';

// An explicit range spans at most 366 elapsed days; Tout omits `from` and the API resolves it.
export const ACCOUNT_PERIODS: readonly ChartPeriod[] = ['ALL', 'LAST_12_MONTHS', 'YTD'];
