import { PlaceholderPage } from '@/features/not-found/PlaceholderPage';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
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
  const { t } = useTranslation();
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
  if (notFound) return <PlaceholderPage />;
  return (
    <div className={styles.page} aria-busy={pending}>
      <InstrumentSheetHeader
        instrument={data?.instrument ?? null}
        pending={pending}
        onEdit={() => setEditing(true)}
        onStatus={() => setStatus(true)}
      />
      {stale ? <p role="status">{t('investments.sheet.stale')}</p> : null}
      {pending ? (
        <InstrumentSheetPageSkeleton />
      ) : query.isError ? (
        <section className="card">
          <p role="alert">{t('investments.sheet.unavailable')}</p>
          <button className="secondary-action" type="button" onClick={() => void query.refetch()}>
            {t('actions.retry')}
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
