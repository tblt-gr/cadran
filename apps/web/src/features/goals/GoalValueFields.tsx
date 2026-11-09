import type { GoalMetric, GoalPeriodicity, GoalValueType } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { AmountInput } from './AmountInput';
import { isStockMetric, valueTypesFor } from './goalFormFields';

interface Props {
  metric: GoalMetric;
  periodicity: GoalPeriodicity;
  valueType: GoalValueType;
  onValueTypeChange: (valueType: GoalValueType) => void;
  /** An exact amount for `AMOUNT`, a percentage for every other value type. */
  value: string;
  onValueChange: (value: string) => void;
  valid: boolean;
  showErrors: boolean;
  errorId: string;
}

/** The value type of a goal and the target entered for it, as two cells of the form grid. */
export function GoalValueFields({
  metric,
  periodicity,
  valueType,
  onValueTypeChange,
  value,
  onValueChange,
  valid,
  showErrors,
  errorId,
}: Props) {
  const { t } = useTranslation();
  const options = valueTypesFor(metric, periodicity);
  const flagged = showErrors && !valid;
  const amount = valueType === 'AMOUNT';
  const valueLabel = t(
    isStockMetric(metric)
      ? 'goals.stockTarget'
      : amount
        ? 'goals.targetAmount'
        : valueType === 'RATE'
          ? 'goals.targetRate'
          : 'goals.ratio',
  );
  return (
    <>
      <label>
        <span>{t('goals.valueType')}</span>
        <select
          disabled={options.length === 1}
          onChange={(event) => onValueTypeChange(event.target.value as GoalValueType)}
          value={valueType}
        >
          {options.map((choice) => (
            <option key={choice} value={choice}>
              {t(
                choice === 'INCOME_SHARE' && periodicity === 'YEARLY'
                  ? 'goals.valueTypes.INCOME_SHARE_YEARLY'
                  : `goals.valueTypes.${choice}`,
              )}
            </option>
          ))}
        </select>
      </label>
      <label>
        <span>{valueLabel}</span>
        {amount ? (
          <AmountInput
            aria-label={valueLabel}
            aria-describedby={flagged ? errorId : undefined}
            invalid={flagged}
            onChange={onValueChange}
            value={value}
          />
        ) : (
          <input
            aria-label={valueLabel}
            aria-describedby={flagged ? errorId : undefined}
            aria-invalid={flagged ? true : undefined}
            inputMode="decimal"
            onChange={(event) => onValueChange(event.target.value)}
            value={value}
          />
        )}
        {amount ? null : (
          <small>{t(valueType === 'RATE' ? 'goals.rateHint' : 'goals.ratioHint')}</small>
        )}
      </label>
    </>
  );
}
