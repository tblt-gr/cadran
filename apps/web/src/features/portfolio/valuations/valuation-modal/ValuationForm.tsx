import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { FormSection } from '@/components/ui/form-layout/FormSection';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import type { PortfolioValuationDraft, PortfolioValuationRequest } from '@cadran/api-client';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import {
  valuesFromDraft,
  buildValuationRequest,
  type ValuationFormValues,
} from '@/features/portfolio/valuations/valuationRequest';
import { ValuationCashHint } from './ValuationCashHint';
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
  const { t, i18n } = useTranslation();
  const [values, setValues] = useState<ValuationFormValues>(() => valuesFromDraft(draft));
  const [advancedOpen, setAdvancedOpen] = useState(Boolean(values.note));
  const [submitted, setSubmitted] = useState(false);
  const built = buildValuationRequest(values, draft.valuedOn, assetCode, version);
  const errors = { ...(submitted ? built.errors : null), ...fieldErrors };
  const total = values.detail === 'TOTAL';
  // One field in both modes: the statement cash, the computed one standing in when left empty.
  const cashField = (
    <DecimalInput
      error={errors['/observedCash']}
      hint={<ValuationCashHint cash={draft.derivedCash} />}
      label={t('portfolio.valuations.form.statementCash')}
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
    <ModalForm className={styles.form} noValidate onSubmit={submit}>
      {saveMessage ? (
        <p className={styles.alert} role="alert">
          {saveMessage}
        </p>
      ) : null}
      {draft.currentValuation ? (
        <p className={styles.notice} role="status">
          {t('portfolio.valuations.form.existing', {
            date: formatCalendarNumericDay(draft.valuedOn),
          })}
        </p>
      ) : null}
      <fieldset className={styles.segmented}>
        <legend>{t('portfolio.valuations.form.mode')}</legend>
        {(
          [
            ['TOTAL', t('portfolio.valuations.form.modeTotal')],
            ['POSITIONS', t('portfolio.valuations.form.modePositions')],
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
      <FormGrid>
        {total ? (
          <>
            <DecimalInput
              error={errors['/totalValue']}
              hint={
                draft.lastTotal
                  ? t('portfolio.valuations.form.lastValue', {
                      amount: formatAmount(
                        draft.lastTotal.value.value,
                        draft.lastTotal.value.assetCode,
                        i18n.language,
                      ),
                      date: formatCalendarNumericDay(draft.lastTotal.valuedOn),
                    })
                  : undefined
              }
              label={t('portfolio.valuations.form.accountTotal')}
              onChange={(value) => set('totalValue', value)}
              value={values.totalValue}
            />
            {cashField}
          </>
        ) : (
          <>
            {draft.positions.length === 0 ? (
              <p className={styles.notice} data-span="full">
                {t('portfolio.valuations.form.noPosition')}
              </p>
            ) : null}

            {cashField}
            <DecimalInput
              error={errors['/observedTotal']}
              hint={t('portfolio.valuations.form.statementTotalHint')}
              label={t('portfolio.valuations.common.statementTotal')}
              onChange={(value) => set('observedTotal', value)}
              value={values.observedTotal}
            />
            <div className={styles.positions} data-span="full">
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
          </>
        )}
        <FormSection
          title={t('portfolio.lifeInsurance.advancedFields')}
          open={advancedOpen || Boolean(errors['/note'])}
          onToggle={setAdvancedOpen}
        >
          <FormField error={errors['/note']} label={t('portfolio.valuations.common.note')} wide>
            {(attributes) => (
              <input
                {...attributes}
                maxLength={200}
                onChange={(event) => set('note', event.target.value)}
                value={values.note}
              />
            )}
          </FormField>
        </FormSection>
      </FormGrid>
      <ModalActions>
        <button className="primary-action" disabled={pending} type="submit">
          {pending ? t('portfolio.lifeInsurance.saving') : t('portfolio.lifeInsurance.save')}
        </button>
      </ModalActions>
    </ModalForm>
  );
}
