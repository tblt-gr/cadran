import type { Instrument } from '@cadran/api-client';
import { Modal } from '@/components/ui/modal/Modal';
import { DuplicateNotice } from '@/features/portfolio/instruments/duplicate-notice/DuplicateNotice';
import { InstrumentForm } from '@/features/portfolio/instruments/instrument-form/InstrumentForm';
import { useInstrumentEditor } from './useInstrumentEditor';

export function InstrumentEditorModal({
  instrument,
  onClose,
}: {
  instrument?: Instrument;
  onClose: () => void;
}) {
  const editor = useInstrumentEditor(instrument, onClose);
  return (
    <Modal
      close={onClose}
      eyebrow="Investissements"
      title={instrument ? 'Modifier l’instrument' : 'Nouvel instrument'}
    >
      {editor.candidates ? (
        <DuplicateNotice
          candidates={editor.candidates}
          editing={Boolean(instrument)}
          onCancel={editor.dismissDuplicate}
          onConfirm={editor.confirmDuplicate}
        />
      ) : null}
      <InstrumentForm
        error={editor.message}
        existingInstrumentId={editor.existingInstrumentId}
        fieldErrors={editor.fieldErrors}
        instrument={instrument}
        onSubmit={editor.submit}
        pending={editor.pending}
      />
    </Modal>
  );
}
