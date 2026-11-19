import i18n from '@/i18n';
import type { ParseKeys } from 'i18next';

/**
 * A label map whose values are read from the dictionary at each access, so a module-level
 * constant follows the active language; the consuming component must re-render on a switch
 * (`useTranslation`).
 */
export function lazyLabels<K extends string, V extends string | null = string>(
  ids: readonly K[],
  prefix: string,
  nullFor: readonly K[] = [],
): Record<K, V> {
  const labels = {} as Record<K, V>;
  for (const id of ids) {
    Object.defineProperty(labels, id, {
      enumerable: true,
      get: () => (nullFor.includes(id) ? null : i18n.t(`${prefix}.${id}` as ParseKeys)) as V,
    });
  }
  return labels;
}
