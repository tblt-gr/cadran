import { listInstruments, type Instrument } from '@cadran/api-client';
import { useIncrementalList } from '@/hooks/incremental-list/useIncrementalList';
import { IncrementalList } from '@/components/ui/incremental-list/IncrementalList';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { HeaderActions } from '@/components/layout/header/HeaderActionsContext';
import { Icon } from '@/components/ui/icon/Icon';
import { authApiOptions } from '@/features/auth/apiOptions';
import { InvestmentsHeader } from '@/features/investments/InvestmentsHeader';
import {
  InstrumentFilters,
  type InstrumentStatusFilter,
} from './instrument-filters/InstrumentFilters';
import { InstrumentEditorModal } from './instrument-editor-modal/InstrumentEditorModal';
import { InstrumentState } from './instrument-state/InstrumentState';
import { InstrumentsPageSkeleton } from './InstrumentsPageSkeleton';
import { InstrumentStatusModal } from './instrument-status-dialog/InstrumentStatusModal';
import { InstrumentTable } from './instrument-table/InstrumentTable';
import { instrumentRequestError } from './instrumentError';
import styles from './InstrumentsPage.module.css';

type Editor = 'create' | Instrument | null;
const PER_PAGE = 20;

function readUrlFilters(): { q: string; status: InstrumentStatusFilter } {
  const params = new URLSearchParams(window.location.search);
  const status = params.get('status');
  return {
    q: params.get('q') ?? '',
    status: status === 'inactive' || status === 'all' ? status : 'active',
  };
}

export function InstrumentsPage() {
  const { t } = useTranslation();
  const initial = readUrlFilters();
  const [editor, setEditor] = useState<Editor>(null);
  const [statusTarget, setStatusTarget] = useState<Instrument | null>(null);
  const [staleNotice, setStaleNotice] = useState(false);
  const [status, setStatus] = useState<InstrumentStatusFilter>(initial.status);
  const [q, setQ] = useState(initial.q);
  const [query, setQuery] = useState(initial.q);
  useEffect(() => {
    const timeout = window.setTimeout(() => {
      setQuery(q);
    }, 250);
    return () => window.clearTimeout(timeout);
  }, [q]);
  useEffect(() => {
    function applyUrlFilters() {
      if (window.location.pathname !== '/investments/instruments') return;
      const next = readUrlFilters();
      setEditor(null);
      setStatus(next.status);
      setQ(next.q);
      setQuery(next.q);
    }
    window.addEventListener('popstate', applyUrlFilters);
    return () => window.removeEventListener('popstate', applyUrlFilters);
  }, []);
  const instruments = useIncrementalList({
    queryKey: ['instruments', status, query],
    request: async ({ signal, page, revision }) => {
      const result = await listInstruments({
        ...authApiOptions(),
        headers: { 'X-List-Revision': revision },
        query: { status, q: query || undefined, page, pageSize: PER_PAGE },
        signal,
      });
      return result;
    },
    requestError: instrumentRequestError,
  });
  const items = instruments.data?.items ?? [];
  const total = instruments.data?.total ?? 0;
  return (
    <div className={styles.page}>
      <InvestmentsHeader path="/investments/instruments" />
      <HeaderActions>
        <button
          aria-label={t('portfolio.instruments.add')}
          className={`primary-action ${styles.add}`}
          onClick={() => setEditor('create')}
          type="button"
        >
          <Icon name="add" size={18} />
          <span className={styles.addLabel}>{t('portfolio.instruments.add')}</span>
        </button>
      </HeaderActions>
      <InstrumentFilters
        onStatusChange={(next) => {
          setStaleNotice(false);
          setStatus(next);
        }}
        status={status}
      />
      {staleNotice ? (
        <p className={styles.notice} role="status">
          Cet instrument a déjà été modifié. La liste a été actualisée.
        </p>
      ) : null}
      {editor ? (
        <InstrumentEditorModal
          instrument={editor === 'create' ? undefined : editor}
          onClose={() => setEditor(null)}
        />
      ) : null}
      {statusTarget ? (
        <InstrumentStatusModal
          instrument={statusTarget}
          onClose={() => setStatusTarget(null)}
          onStale={() => setStaleNotice(true)}
        />
      ) : null}
      <section className={styles.toolbar}>
        <label>
          <span>{t('portfolio.instruments.search')}</span>
          <input onChange={(event) => setQ(event.target.value)} value={q} />
        </label>
      </section>
      {instruments.isPending && instruments.data === undefined ? (
        <InstrumentsPageSkeleton status={t('portfolio.instruments.loading')} />
      ) : instruments.isError && !instruments.data ? (
        <InstrumentState
          error
          onRetry={() => void instruments.refetch()}
          title="Les instruments sont indisponibles"
        />
      ) : total === 0 ? (
        <InstrumentState title={query ? 'Aucun instrument trouvé' : 'Aucun instrument'}>
          <>
            {!query ? (
              <button className="primary-action" onClick={() => setEditor('create')} type="button">
                Créer le premier instrument
              </button>
            ) : null}
          </>
        </InstrumentState>
      ) : (
        <IncrementalList items={items} query={instruments}>
          {(visibleItems) => (
            <InstrumentTable
              items={visibleItems}
              onEdit={setEditor}
              onStatus={(item) => {
                setStaleNotice(false);
                setStatusTarget(item);
              }}
            />
          )}
        </IncrementalList>
      )}
    </div>
  );
}
