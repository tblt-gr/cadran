/** `FORTNIGHTLY_ANNUAL_CAPITALISATION` read as words, for the one place that must quote a token. */
export function humanToken(token: string): string {
  const words = token.toLowerCase().replaceAll('_', ' ').trim();

  return words.charAt(0).toUpperCase() + words.slice(1);
}
