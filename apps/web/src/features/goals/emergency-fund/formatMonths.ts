import { formatDecimal } from '@/lib/decimal';

/** A month count with one decimal at most; a whole count drops its ".0" ("6", not "6,0"). */
export function formatMonths(value: string, locale: string): string {
  return formatDecimal(value.replace(/\.0+$/, ''), locale);
}
