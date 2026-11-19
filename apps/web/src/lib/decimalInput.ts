import i18n from '@/i18n';

/**
 * A typed decimal ready for canonical validation. French accepts a decimal comma; English
 * keeps the comma so validation refuses it, because `1,234` would otherwise be read as 1.234
 * by someone who meant a thousand separator.
 */
export function normalizeDecimalInput(raw: string): string {
  const value = raw.trim();
  return i18n.language === 'en' ? value : value.replace(',', '.');
}
