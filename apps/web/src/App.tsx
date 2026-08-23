import { getFoundationStatus } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import './App.css';

function App() {
  const { t } = useTranslation();
  const status = useQuery({
    queryKey: ['foundation-status'],
    queryFn: async ({ signal }) => {
      const response = await getFoundationStatus({
        baseUrl: window.location.origin,
        signal,
        throwOnError: true,
      });

      return response.data;
    },
    retry: false,
  });

  if (status.isPending) {
    return (
      <main className="foundation-state" aria-labelledby="foundation-title">
        <h1 id="foundation-title">{t('app.name')}</h1>
        <p role="status">{t('foundation.loading')}</p>
      </main>
    );
  }

  if (status.isError) {
    return (
      <main className="foundation-state" aria-labelledby="foundation-title">
        <div role="alert">
          <h1 id="foundation-title">{t('app.name')}</h1>
          <p>{t('foundation.error')}</p>
        </div>
        <button
          type="button"
          onClick={() => {
            void status.refetch();
          }}
        >
          {t('foundation.retry')}
        </button>
      </main>
    );
  }

  return (
    <main className="foundation-state" aria-labelledby="foundation-title">
      <p className="eyebrow">{t('foundation.apiVersion', { version: status.data.apiVersion })}</p>
      <h1 id="foundation-title">{t('app.name')}</h1>
      <p role="status">{t('foundation.ready')}</p>
    </main>
  );
}

export default App;
