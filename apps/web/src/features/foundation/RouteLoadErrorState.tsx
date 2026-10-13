import type { ParseKeys } from 'i18next';
import { useTranslation } from 'react-i18next';
import { FoundationErrorState } from './FoundationErrorState';

/**
 * A page chunk that cannot be fetched. The shell header already shows the route
 * title; the heading keeps the region named for assistive technology.
 */
export function RouteLoadErrorState({ titleKey }: { titleKey: ParseKeys }) {
  const { t } = useTranslation();

  return (
    <>
      <h2 className="sr-only">{t(titleKey)}</h2>
      <FoundationErrorState
        copy={{
          description: t('routes.loadError.description'),
          retryLabel: t('routes.loadError.reload'),
          title: t('routes.loadError.title'),
        }}
        retry={() => window.location.reload()}
      />
    </>
  );
}
