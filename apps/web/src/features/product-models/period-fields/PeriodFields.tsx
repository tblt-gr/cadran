import type { ProductCapability, ProductRuleKind, ProductYieldKind } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { FieldRow } from '@/features/product-models/field-row/FieldRow';
import { RULE_KINDS, ruleKindAvailable, ruleValueType } from '@/features/product-models/ruleKinds';
import {
  periodProblems,
  type PeriodValues,
} from '@/features/product-models/period-fields/periodValues';
import { RateScaleEditor } from '@/features/product-models/period-fields/rate-scale-editor/RateScaleEditor';
import styles from './PeriodFields.module.css';

interface PeriodFieldsProps {
  value: PeriodValues;
  yieldKind: ProductYieldKind;
  capabilities: ProductCapability[];
  /**
   * The kinds this period may state at all. It defaults to every kind, which
   * is what describing a model from scratch offers; a caller that already
   * knows which kinds its subject can carry — an account, whose authority has
   * settled the question — passes the shorter list instead of offering choices
   * the API would refuse. Those caller-supplied kinds are not re-disabled
   * against yield and capabilities the caller does not have.
   */
  kinds?: ProductRuleKind[];
  showErrors: boolean;
  onChange: (value: PeriodValues) => void;
}

/**
 * One dated period being described: the rule it states, its value in the shape
 * that kind requires, and the days it covers. A kind the model cannot carry —
 * a rate on a market yield, a ceiling without the capability that measures it —
 * is offered disabled rather than hidden, so the reason is visible. The value
 * is exact text throughout; nothing is parsed to a number here.
 */
export function PeriodFields({
  value,
  yieldKind,
  capabilities,
  kinds,
  showErrors,
  onChange,
}: PeriodFieldsProps) {
  const { t } = useTranslation();
  const offered = kinds ?? RULE_KINDS;
  const constrainAvailability = kinds === undefined;
  const problems = showErrors ? periodProblems(value) : [];
  const has = (field: string) => problems.includes(field);
  const valueType = ruleValueType(value.kind);

  function patch(next: Partial<PeriodValues>) {
    onChange({ ...value, ...next });
  }

  return (
    <div className={styles.period}>
      <FieldRow label={t('productModels.period.kind')}>
        {({ fieldId }) => (
          <select
            id={fieldId}
            onChange={(event) => patch({ kind: event.target.value as ProductRuleKind })}
            value={value.kind}
          >
            {offered.map((kind) => (
              <option
                disabled={
                  constrainAvailability && !ruleKindAvailable(kind, yieldKind, capabilities)
                }
                key={kind}
                value={kind}
              >
                {t(`catalog.rules.kinds.${kind}`)}
              </option>
            ))}
          </select>
        )}
      </FieldRow>

      {valueType === 'AMOUNT' ? (
        <div className={styles.amount}>
          <FieldRow
            error={has('amount') ? t('productModels.period.errors.amount') : undefined}
            label={t('productModels.period.amount')}
          >
            {({ fieldId, describedBy }) => (
              <input
                aria-describedby={describedBy}
                aria-invalid={has('amount') ? true : undefined}
                id={fieldId}
                inputMode="decimal"
                onChange={(event) => patch({ amount: event.target.value })}
                value={value.amount}
              />
            )}
          </FieldRow>
          <FieldRow
            error={has('amountAssetCode') ? t('productModels.period.errors.assetCode') : undefined}
            label={t('productModels.period.assetCode')}
          >
            {({ fieldId, describedBy }) => (
              <input
                aria-describedby={describedBy}
                aria-invalid={has('amountAssetCode') ? true : undefined}
                id={fieldId}
                onChange={(event) => patch({ amountAssetCode: event.target.value.toUpperCase() })}
                value={value.amountAssetCode}
              />
            )}
          </FieldRow>
        </div>
      ) : null}

      {valueType === 'PERCENTAGE' ? (
        <>
          <RateScaleEditor
            brackets={value.brackets}
            invalid={has('brackets')}
            onBracketsChange={(brackets) => patch({ brackets })}
            onRateApplicationChange={(rateApplication) => patch({ rateApplication })}
            onScaleShapeChange={(scaleShape) => patch({ scaleShape })}
            onSingleRateChange={(singleRate) => patch({ singleRate })}
            rateApplication={value.rateApplication}
            scaleShape={value.scaleShape}
            singleRate={value.singleRate}
          />
          {has('brackets') ? (
            <p className={styles.error} role="alert">
              {t('productModels.period.errors.brackets')}
            </p>
          ) : null}
        </>
      ) : null}

      {valueType === 'TEXT' ? (
        <FieldRow
          error={has('text') ? t('productModels.period.errors.text') : undefined}
          hint={t('productModels.period.textHint')}
          label={t('productModels.period.text')}
        >
          {({ fieldId, describedBy }) => (
            <input
              aria-describedby={describedBy}
              aria-invalid={has('text') ? true : undefined}
              id={fieldId}
              onChange={(event) => patch({ text: event.target.value.toUpperCase() })}
              value={value.text}
            />
          )}
        </FieldRow>
      ) : null}

      <div className={styles.dates}>
        <FieldRow
          error={has('validFrom') ? t('productModels.period.errors.validFrom') : undefined}
          label={t('productModels.period.validFrom')}
        >
          {({ fieldId, describedBy }) => (
            <input
              aria-describedby={describedBy}
              aria-invalid={has('validFrom') ? true : undefined}
              id={fieldId}
              onChange={(event) => patch({ validFrom: event.target.value })}
              type="date"
              value={value.validFrom}
            />
          )}
        </FieldRow>
        <FieldRow
          error={has('validTo') ? t('productModels.period.errors.validTo') : undefined}
          hint={t('productModels.period.validToHint')}
          label={t('productModels.period.validTo')}
        >
          {({ fieldId, describedBy }) => (
            <input
              aria-describedby={describedBy}
              aria-invalid={has('validTo') ? true : undefined}
              id={fieldId}
              onChange={(event) => patch({ validTo: event.target.value })}
              type="date"
              value={value.validTo}
            />
          )}
        </FieldRow>
      </div>
    </div>
  );
}
