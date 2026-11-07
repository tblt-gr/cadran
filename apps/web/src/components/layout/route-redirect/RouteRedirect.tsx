import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import type { ParseKeys } from 'i18next';

/** Client-side redirect; keeps query string and hash so legacy deep links lose no state. */
export function RouteRedirect({
  to,
  statusKey = 'routes.redirecting',
  keepParams,
}: {
  to: string;
  /** Copies only these query parameters and no hash, for a legacy URL whose input is not trusted. */
  keepParams?: readonly string[];
  statusKey?: ParseKeys;
}) {
  const { t } = useTranslation();

  useEffect(() => {
    let { search, hash } = window.location;
    if (keepParams) {
      const source = new URLSearchParams(search);
      const kept = new URLSearchParams();
      for (const name of keepParams) {
        const value = source.get(name);
        if (value !== null) kept.set(name, value);
      }
      search = kept.size > 0 ? `?${kept.toString()}` : '';
      hash = '';
    }
    window.history.replaceState({}, '', `${to}${search}${hash}`);
    window.dispatchEvent(new PopStateEvent('popstate'));
    // keepParams is a literal list at each call site.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [to]);

  return <p role="status">{t(statusKey)}</p>;
}
