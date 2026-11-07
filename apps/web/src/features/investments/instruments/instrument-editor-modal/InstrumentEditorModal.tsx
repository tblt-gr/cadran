import type { Instrument } from '@cadran/api-client';
import { useEffect, useRef, type RefObject } from 'react';
import { Modal } from '@/components/ui/modal/Modal';
import { DuplicateNotice } from '@/features/investments/instruments/duplicate-notice/DuplicateNotice';
import { ExistingInstrumentChoice } from '@/features/investments/instruments/existing-instrument-choice/ExistingInstrumentChoice';
import {
  InstrumentForm,
  type InstrumentInitial,
  type InstrumentLock,
} from '@/features/investments/instruments/instrument-form/InstrumentForm';
import { useInstrumentEditor } from './useInstrumentEditor';
import { useInstrumentReuse } from './useInstrumentReuse';

export function InstrumentEditorModal({
  instrument,
  initial,
  lock,
  onClose,
  onSaved,
  returnFocus,
}: {
  instrument?: Instrument;
  initial?: InstrumentInitial;
  lock?: InstrumentLock;
  onClose: () => void;
  onSaved?: (instrument: Instrument) => void;
  returnFocus?: RefObject<HTMLElement | null>;
}) {
  const active = useRef(true);
  useEffect(() => {
    active.current = true;
    return () => {
      active.current = false;
    };
  }, []);
  const close = () => {
    // Requests may finish after dismissal; their cache refresh still runs, their UI effects do not.
    active.current = false;
    onClose();
  };
  const saved = (item: Instrument) => {
    if (!active.current) return;
    onSaved?.(item);
    close();
  };
  const editor = useInstrumentEditor(instrument, saved);
  const reuse = useInstrumentReuse({
    existingId: onSaved ? editor.existingInstrumentId : undefined,
    initial,
    lock,
    onSaved: saved,
  });
  const pending = editor.pending || reuse.use.isPending;
  const existing = reuse.existing.data ?? null;
  return (
    <Modal
      intent={instrument ? 'edit' : 'create'}
      close={close}
      returnFocus={returnFocus}
      eyebrow={onSaved && !instrument ? 'Nouvel instrument' : undefined}
      title={
        instrument ? 'Modifier l’instrument' : onSaved ? 'Créer un instrument' : 'Nouvel instrument'
      }
    >
      {editor.candidates ? (
        <DuplicateNotice
          candidates={editor.candidates}
          editing={Boolean(instrument)}
          onCancel={editor.dismissDuplicate}
          onConfirm={editor.confirmDuplicate}
          onUse={onSaved ? (id) => reuse.use.mutate(id) : undefined}
          pending={pending}
        />
      ) : null}
      <InstrumentForm
        error={
          onSaved && editor.existingInstrumentId ? (
            <ExistingInstrumentChoice
              instrument={existing}
              pending={reuse.existing.isPending || pending}
              failed={reuse.existing.isError}
              compatible={existing ? reuse.compatible(existing) : false}
              onRetry={() => void reuse.existing.refetch()}
              onUse={() => reuse.use.mutate(editor.existingInstrumentId!)}
            />
          ) : (
            editor.message
          )
        }
        existingInstrumentId={onSaved ? undefined : editor.existingInstrumentId}
        fieldErrors={editor.fieldErrors}
        instrument={instrument}
        initial={initial}
        lock={lock}
        onSubmit={editor.submit}
        pending={pending}
      />
      {reuse.use.isError ? (
        <p role="alert">
          Impossible d’utiliser cet instrument. Vérifiez sa devise et son type, puis réessayez.
        </p>
      ) : null}
    </Modal>
  );
}
