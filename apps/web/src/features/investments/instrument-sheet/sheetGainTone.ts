import { signedPresentation } from '@/lib/decimal';

/** Hidden, zero and missing figures carry no directional tone. */
export function sheetGainTone(value: string | null | undefined): 'positive' | 'negative' | null {
  const sign = value == null ? 0 : signedPresentation('', value).sign;
  return sign < 0 ? 'negative' : sign > 0 ? 'positive' : null;
}
