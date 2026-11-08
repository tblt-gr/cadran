import { useId } from 'react';
import { useTranslation } from 'react-i18next';
import { AmountInput } from '@/features/goals/AmountInput';

/** Optional exact target margin, with its unit and field-specific validation. */
export function GoalToleranceField({
  value,
  onChange,
  amount,
  assetCode,
  invalid,
}: {
  value: string;
  onChange: (value: string) => void;
  amount: boolean;
  assetCode: string;
  invalid: boolean;
}) {
  const { t } = useTranslation();
  const hintId = useId();
  const errorId = useId();
  return (
    <label>
      <span>
        {t('goals.tolerance')} ({amount ? assetCode : t('goals.tolerancePoints')})
      </span>
      <AmountInput
        aria-label={t('goals.tolerance')}
        aria-describedby={`${hintId}${invalid ? ` ${errorId}` : ''}`}
        invalid={invalid}
        onChange={onChange}
        value={value}
      />
      <small id={hintId}>{t('goals.toleranceHint')}</small>
      {invalid ? (
        <small id={errorId} role="alert">
          {t('goals.validation.tolerance')}
        </small>
      ) : null}
    </label>
  );
}
