export type HistoryPeriod = '12' | '24' | 'all';

export const HISTORY_PERIODS: { value: HistoryPeriod; label: string }[] = [
  { value: '12', label: '12 mois' },
  { value: '24', label: '24 mois' },
  { value: 'all', label: 'Tout' },
];

export function parseHistoryPeriod(raw: string | null): HistoryPeriod {
  return raw === '12' || raw === 'all' ? raw : '24';
}

/** First day of the range: `to` minus whole calendar months, or undefined for "since the start". */
export function historyFrom(to: string, period: HistoryPeriod): string | undefined {
  if (period === 'all') return undefined;
  const [year, month, day] = to.split('-').map(Number);
  const index = year * 12 + (month - 1) - Number(period);
  const targetYear = Math.floor(index / 12);
  const targetMonth = (index % 12) + 1;
  const lastDay = new Date(Date.UTC(targetYear, targetMonth, 0)).getUTCDate();
  const pad = (value: number, size = 2) => String(value).padStart(size, '0');
  return `${pad(targetYear, 4)}-${pad(targetMonth)}-${pad(Math.min(day, lastDay))}`;
}
