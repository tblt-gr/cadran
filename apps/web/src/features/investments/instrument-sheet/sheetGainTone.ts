/** Sign of a server decimal; zero and a missing figure carry no tone. */
export function sheetGainTone(value: string | null | undefined): 'positive' | 'negative' | null {
  if (value == null || !/[1-9]/.test(value)) return null;
  return value.startsWith('-') ? 'negative' : 'positive';
}
