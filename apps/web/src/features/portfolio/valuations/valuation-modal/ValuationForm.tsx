import type { PortfolioValuationDraft, PortfolioValuationRequest } from '@cadran/api-client';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import {
  valuesFromDraft,
  buildValuationRequest,
  type ValuationFormValues,
} from '@/features/portfolio/valuations/valuationRequest';
import { DecimalInput } from './DecimalInput';
import { FormField } from './FormField';
import { PositionPriceRow } from './PositionPriceRow';
import styles from './ValuationForm.module.css';

/** Pure form of step 2: typed values in, a request body out; every figure is computed by the server. */
export function ValuationForm({
  assetCode,
  draft,
  fieldErrors,
  onSubmit,
  pending,
  saveMessage,
  version,
}: {
  assetCode: string;
  draft: PortfolioValuationDraft;
  fieldErrors: Record<string, string>;
  onSubmit: (request: PortfolioValuationRequest) => void;
  pending: boolean;
  saveMessage?: string;
  version: number | null;
}) {
  const { i18n } = useTranslation();
  const [values, setValues] = useState<ValuationFormValues>(() => valuesFromDraft(draft));
  const [submitted, setSubmitted] = useState(false);
  const built = buildValuationRequest(values, draft.valuedOn, assetCode, version);
  const errors = { ...(submitted ? built.errors : null), ...fieldErrors };
  const total = values.detail === 'TOTAL';
  const derivedCash = formatAmount(
    draft.derivedCash.value,
    draft.derivedCash.assetCode,
    i18n.language,
  );
  // One field in both modes: the statement cash, the computed one standing in when left empty.
  const cashField = (
    <DecimalInput
      error={errors['/observedCash']}
      hint={`Laissez vide pour retenir les espèces calculées : ${derivedCash}`}
      label="Espèces du relevé"
      onChange={(value) => set('observedCash', value)}
      value={values.observedCash}
    />
  );

  function set<K extends keyof ValuationFormValues>(key: K, value: ValuationFormValues[K]) {
    setValues((current) => ({ ...current, [key]: value }));
  }
  function submit(event: FormEvent) {
    event.preventDefault();
    setSubmitted(true);
    if (built.request) onSubmit(built.request);
  }

  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {saveMessage ? (
        <p className={styles.alert} role="alert">
          {saveMessage}
        </p>
      ) : null}
      {draft.currentValuation ? (
        <p className={styles.notice} role="status">
          Une valorisation existe déjà le {formatCalendarNumericDay(draft.valuedOn)} : elle sera
          remplacée et conservée dans l’historique.
        </p>
      ) : null}
      <fieldset className={styles.segmented}>
        <legend>Mode de saisie</legend>
        {(
          [
            ['TOTAL', 'Valeur totale'],
            ['POSITIONS', 'Détail par ligne'],
          ] as const
        ).map(([detail, label]) => (
          <label className={styles.segment} key={detail}>
            <input
              checked={values.detail === detail}
              name="valuation-detail"
              onChange={() => set('detail', detail)}
              type="radio"
            />
            {label}
          </label>
        ))}
      </fieldset>
      {total ? (
        <div className={styles.fields}>
          <DecimalInput
            error={errors['/totalValue']}
            hint={
              draft.lastTotal
                ? `Dernière valeur : ${formatAmount(draft.lastTotal.value.value, draft.lastTotal.value.assetCode, i18n.language)} au ${formatCalendarNumericDay(draft.lastTotal.valuedOn)}`
                : undefined
            }
            label="Valeur totale du compte"
            onChange={(value) => set('totalValue', value)}
            value={values.totalValue}
          />
          {cashField}
        </div>
      ) : (
        <>
          {draft.positions.length === 0 ? (
            <p className={styles.notice}>Aucune position détenue à cette date.</p>
          ) : null}
          <div className={styles.positions}>
            {draft.positions.map((position, index) => (
              <PositionPriceRow
                errors={errors}
                index={index}
                key={position.instrument.id}
                onChange={(row) =>
                  set(
                    'rows',
                    values.rows.map((current, at) => (at === index ? row : current)),
                  )
                }
                position={position}
                value={values.rows[index]!}
              />
            ))}
          </div>
          <div className={styles.fields}>
            {cashField}
            <DecimalInput
              error={errors['/observedTotal']}
              hint="Facultatif : signale un écart avec le total calculé."
              label="Total du relevé"
              onChange={(value) => set('observedTotal', value)}
              value={values.observedTotal}
            />
          </div>
        </>
      )}
      <FormField error={errors['/note']} label="Note" wide>
        {(attributes) => (
          <input
            {...attributes}
            maxLength={200}
            onChange={(event) => set('note', event.target.value)}
            value={values.note}
          />
        )}
      </FormField>
      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {pending ? 'Enregistrement…' : 'Enregistrer'}
        </button>
      </div>
    </form>
  );
}
