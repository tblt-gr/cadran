import { useEffect } from 'react';
import { useTranslation } from 'react-i18next';
import type { ParseKeys } from 'i18next';

/** Client-side redirect; keeps query string and hash so legacy deep links lose no state. */
export function RouteRedirect({
  to,
  statusKey = 'routes.redirecting',
}: {
  to: string;
  statusKey?: ParseKeys;
}) {
  const { t } = useTranslation();

  useEffect(() => {
    const { search, hash } = window.location;
    window.history.replaceState({}, '', `${to}${search}${hash}`);
    window.dispatchEvent(new PopStateEvent('popstate'));
  }, [to]);

  return <p role="status">{t(statusKey)}</p>;
}
