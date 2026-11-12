import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import type { PortfolioTakeoverRequest } from '@cadran/api-client';
import { useMemo, useState, type FormEvent } from 'react';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { formatCalendarDay } from '@/lib/decimal';
import { TakeoverInput } from './TakeoverInput';
import { TakeoverLineRow } from './TakeoverLineRow';
import { TakeoverSummary } from './TakeoverSummary';
import {
  buildTakeoverRequest,
  emptyLine,
  mapTakeoverErrors,
  MAX_TAKEOVER_LINES,
  mergeTakeoverErrors,
  type TakeoverFieldErrors,
  type TakeoverFormValues,
} from './takeoverRequest';
import { useTakeoverPreview } from './useTakeoverPreview';
import styles from './PortfolioTakeoverForm.module.css';

/**
 * Every position held at one date, then the cash. A pure form: the modal owns the card and the
 * title. Values, unit costs and totals only ever come from the server preview.
 */
export function PortfolioTakeoverForm({
  account,
  defaultDate,
  onEdit,
  onSubmit,
  pending,
  saveMessage,
  serverErrors,
}: {
  account: { id: string; assetCode: string };
  defaultDate: string;
  /** Called on every change, so the modal knows there is something to lose. */
  onEdit: () => void;
  onSubmit: (request: PortfolioTakeoverRequest) => void;
  pending: boolean;
  saveMessage?: string;
  /** The per-line refusals of the last save. */
  serverErrors?: TakeoverFieldErrors;
}) {
  const [values, setValues] = useState<TakeoverFormValues>(() => ({
    takenOverOn: defaultDate,
    lines: [emptyLine(defaultDate)],
    cash: '',
  }));
  const [submitted, setSubmitted] = useState(false);
  const built = useMemo(() => buildTakeoverRequest(values), [values]);
  const preview = useTakeoverPreview(account.id, built.request);
  const errors = mergeTakeoverErrors(
    submitted ? built.errors : undefined,
    preview.data ? mapTakeoverErrors(preview.data.errors) : undefined,
    serverErrors,
  );

  function update(next: TakeoverFormValues) {
    setValues(next);
    onEdit();
  }
  function changeDate(takenOverOn: string) {
    // A price date that followed the takeover date keeps following it.
    update({
      ...values,
      takenOverOn,
      lines: values.lines.map((line) =>
        line.priceDate === values.takenOverOn ? { ...line, priceDate: takenOverOn } : line,
      ),
    });
  }
  function submit(event: FormEvent) {
    event.preventDefault();
    setSubmitted(true);
    if (built.request) onSubmit(built.request);
  }

  const validDate = /^\d{4}-\d{2}-\d{2}$/.test(values.takenOverOn);
  const previewLines = preview.data?.lines;
  return (
    <ModalForm className={styles.form} noValidate onSubmit={submit}>
      {saveMessage ? (
        <p className={styles.alert} role="alert">
          {saveMessage}
        </p>
      ) : null}
      {errors.form ? (
        <p className={styles.alert} role="alert">
          {errors.form}
        </p>
      ) : null}
      <div className={styles.top}>
        <TakeoverInput
          error={errors.date}
          hint="La date de votre dernier relevé. La performance est calculée à partir de cette date."
          label="Date de reprise"
          onChange={(event) => changeDate(event.target.value)}
          type="date"
          value={values.takenOverOn}
        />
      </div>
      {values.lines.map((line, index) => (
        <TakeoverLineRow
          assetCode={account.assetCode}
          errors={errors.lines[index] ?? {}}
          index={index}
          key={line.key}
          line={line}
          onChange={(patch) =>
            update({
              ...values,
              lines: values.lines.map((item) => (item === line ? { ...item, ...patch } : item)),
            })
          }
          onRemove={() =>
            update({ ...values, lines: values.lines.filter((item) => item !== line) })
          }
          preview={previewLines?.length === values.lines.length ? previewLines[index] : undefined}
          previewPending={preview.isFetching}
          removable={values.lines.length > 1}
        />
      ))}
      <button
        className={`secondary-action ${styles.add}`}
        disabled={values.lines.length >= MAX_TAKEOVER_LINES}
        onClick={() =>
          update({ ...values, lines: [...values.lines, emptyLine(values.takenOverOn)] })
        }
        type="button"
      >
        Ajouter une ligne
      </button>
      <div className={styles.top}>
        <TakeoverInput
          autoComplete="off"
          error={errors.cash}
          inputMode="decimal"
          label={
            validDate ? `Espèces au ${formatCalendarDay(values.takenOverOn, 'fr')}` : 'Espèces'
          }
          onChange={(event) => update({ ...values, cash: event.target.value })}
          value={values.cash}
        />
      </div>
      <TakeoverSummary
        assetCode={account.assetCode}
        failed={preview.isError}
        pending={preview.isFetching}
        preview={preview.data}
      />
      <ModalActions>
        <button className="primary-action" disabled={pending} type="submit">
          {pending ? 'Enregistrement…' : 'Enregistrer la reprise'}
        </button>
      </ModalActions>
    </ModalForm>
  );
}
