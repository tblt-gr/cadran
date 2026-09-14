import type {
  Account,
  CreateRecurrenceRequest,
  Recurrence,
  RecurrenceCandidate,
  UpdateRecurrenceRequest,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { RecurrenceForm } from '@/features/recurrences/recurrence-form/RecurrenceForm';

interface RecurrenceEditorProps {
  accounts: Account[];
  candidate?: RecurrenceCandidate;
  close: () => void;
  onSubmit: (body: CreateRecurrenceRequest | UpdateRecurrenceRequest) => void;
  pending: boolean;
  recurrence?: Recurrence;
  submitError: string | null;
}

export function RecurrenceEditor(props: RecurrenceEditorProps) {
  const { t } = useTranslation();
  const editing = props.recurrence !== undefined;
  return (
    <Modal
      close={props.close}
      eyebrow={t(editing ? 'recurrences.form.editEyebrow' : 'recurrences.form.createEyebrow')}
      title={t(editing ? 'recurrences.form.editTitle' : 'recurrences.form.createTitle')}
    >
      <RecurrenceForm
        {...props}
        key={props.recurrence?.id ?? props.candidate?.fingerprint ?? 'create'}
      />
    </Modal>
  );
}
