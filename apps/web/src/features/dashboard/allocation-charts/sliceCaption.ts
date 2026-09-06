import { formatSharePercent } from '@/lib/formatSharePercent';

/** Slice title plus its backend share, joined by a middle dot. */
export function sliceCaption(label: string, percentDisplay: string | null): string {
  if (percentDisplay === null) {
    return label;
  }

  return `${label} · ${formatSharePercent(percentDisplay, { fractionDigits: 2 })}`;
}
