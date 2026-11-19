import type { ParseKeys } from 'i18next';
import type { TFunction } from 'i18next';

export type InstrumentOption = readonly [string, string];

const TYPE_CODES = ['EQUITY', 'ETF', 'FUND', 'BOND', 'EURO_FUND', 'OTHER'] as const;
const CLASS_CODES = [
  'EQUITY',
  'BOND',
  'MONEY_MARKET',
  'REAL_ESTATE',
  'COMMODITY',
  'MIXED',
  'OTHER',
] as const;
const REGION_CODES = [
  'FRANCE',
  'EUROPE',
  'NORTH_AMERICA',
  'ASIA_PACIFIC',
  'EMERGING',
  'WORLD',
  'OTHER',
] as const;

const options = (t: TFunction, group: string, codes: readonly string[]): InstrumentOption[] =>
  codes.map((code) => [code, t(`investments.instruments.${group}.${code}` as ParseKeys)] as const);

export const instrumentTypeOptions = (t: TFunction) => options(t, 'types', TYPE_CODES);
export const instrumentClassOptions = (t: TFunction) => options(t, 'classes', CLASS_CODES);
export const instrumentRegionOptions = (t: TFunction) => options(t, 'regions', REGION_CODES);

export function validPrecision(value: string) {
  return /^\d{1,2}$/.test(value) && Number(value) <= 12;
}
