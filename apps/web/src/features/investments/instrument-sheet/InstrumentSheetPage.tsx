import { useState } from 'react';
import { useBreadcrumbAccount } from '@/components/layout/breadcrumb/BreadcrumbContext';
import { InstrumentEditorModal } from '@/features/investments/instruments/instrument-editor-modal/InstrumentEditorModal';
import { InstrumentStatusModal } from '@/features/investments/instruments/instrument-status-dialog/InstrumentStatusModal';
import { InstrumentRequestError } from '@/features/investments/instruments/instrumentError';
import { InstrumentSheetHeader } from './instrument-sheet-header/InstrumentSheetHeader';
import { InstrumentSheetPageSkeleton } from './InstrumentSheetPageSkeleton';
import { InstrumentSheetContent } from './InstrumentSheetContent';
import { useInstrumentHolding } from './useInstrumentHolding';
import styles from './InstrumentSheetPage.module.css';

export function InstrumentSheetPage({ instrumentId }: { instrumentId: string }) {
  const query = useInstrumentHolding(instrumentId);
  const [editing, setEditing] = useState(false);
  const [status, setStatus] = useState(false);
  const [stale, setStale] = useState(false);
  const data = query.data ?? null;
  const pending = query.isPending;
  const notFound =
    query.error instanceof InstrumentRequestError && query.error.code === 'instrument_not_found';
  useBreadcrumbAccount(
    `/investments/instruments/${instrumentId}`,
    data?.instrument.name ?? null,
    pending,
  );
  return (
    <div className={styles.page} aria-busy={pending}>
      <InstrumentSheetHeader
        instrument={data?.instrument ?? null}
        pending={pending}
        onEdit={() => setEditing(true)}
        onStatus={() => setStatus(true)}
      />
      {stale ? (
        <p role="status">Cet instrument a déjà été modifié. La fiche a été actualisée.</p>
      ) : null}
      {pending ? (
        <InstrumentSheetPageSkeleton />
      ) : notFound ? (
        <section className="card">
          <h2>Instrument introuvable</h2>
        </section>
      ) : query.isError ? (
        <section className="card">
          <p role="alert">La fiche est indisponible.</p>
          <button className="secondary-action" type="button" onClick={() => void query.refetch()}>
            Réessayer
          </button>
        </section>
      ) : (
        <InstrumentSheetContent data={data} />
      )}
      {editing && data ? (
        <InstrumentEditorModal instrument={data.instrument} onClose={() => setEditing(false)} />
      ) : null}
      {status && data ? (
        <InstrumentStatusModal
          instrument={data.instrument}
          onClose={() => setStatus(false)}
          onStale={() => setStale(true)}
        />
      ) : null}
    </div>
  );
}
