import { formatRatioPercentage } from '@/lib/formatRatioPercentage';

/** `2026-07` as "juillet 2026", lower case since it always sits inside a sentence. */
export function monthYear(month: string, locale: string): string {
  return new Intl.DateTimeFormat(locale, {
    month: 'long',
    timeZone: 'UTC',
    year: 'numeric',
  }).format(new Date(`${month}-01T12:00:00Z`));
}

/** `2026-07` as "juillet". */
export function monthName(month: string, locale: string): string {
  return new Intl.DateTimeFormat(locale, { month: 'long', timeZone: 'UTC' }).format(
    new Date(`${month}-01T12:00:00Z`),
  );
}

/** The sign is always written (plus or minus), so a gain and a loss differ without colour. */
export function signedRatioPercentage(ratio: string, locale: string): string {
  const text = formatRatioPercentage(ratio, locale);
  if (!/[1-9]/.test(text)) return text;

  return ratio.startsWith('-') ? `−${text.replace(/^[-−]/, '')}` : `+${text}`;
}
