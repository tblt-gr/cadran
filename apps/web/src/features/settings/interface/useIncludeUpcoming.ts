import { useInterfacePreferences } from './useInterfacePreferences';

/**
 * What the monthly reads must pass as `includeUpcoming`. The server never reads the stored
 * setting itself, so the page waits for it (`ready`) before any read. If the preference cannot
 * be read, the documented default (on) applies rather than blocking the figures.
 */
export function useIncludeUpcoming(): { ready: boolean; includeUpcoming: boolean } {
  const preferences = useInterfacePreferences();

  return {
    ready: !preferences.isPending,
    includeUpcoming: preferences.data?.includeUpcoming ?? true,
  };
}
