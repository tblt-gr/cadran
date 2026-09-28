import { previewGoalSchedule } from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import {
  isCanonicalUnsignedDecimal,
  isZeroDecimal,
  subtractDecimals,
  sumDecimals,
  formatAmount,
} from '@/lib/decimal';
import { AmountInput } from '@/features/goals/AmountInput';
import { goalErrorKind, goalRequestError, type GoalErrorKind } from '@/features/goals/goalError';
import styles from './GoalScheduleEditor.module.css';

export type GoalScheduleMode = 'LINEAR' | 'CUSTOM';

export interface GoalScheduleValue {
  mode: GoalScheduleMode;
  /** Twelve canonical amounts, January first; only meaningful in CUSTOM mode. */
  parts: string[];
}

interface Props {
  assetCode: string;
  disabled?: boolean;
  onChange: (value: GoalScheduleValue) => void;
  targetAmount: string;
  value: GoalScheduleValue;
}

/** A reference year with no significance beyond giving `Intl` a date to name a month from. */
const MONTH_NAME_YEAR = 2000;

function monthName(index: number, locale: string): string {
  return new Intl.DateTimeFormat(locale, { month: 'long', timeZone: 'UTC' }).format(
    new Date(Date.UTC(MONTH_NAME_YEAR, index, 1)),
  );
}

/** The exact sum of parts that are, so far, canonical decimals; blank or invalid entries count as zero. */
function sumOfParts(parts: string[]): string {
  return sumDecimals(parts.map((part) => (isCanonicalUnsignedDecimal(part) ? part : '0')));
}

export function GoalScheduleEditor({ assetCode, disabled, onChange, targetAmount, value }: Props) {
  const { t, i18n } = useTranslation();
  const [previewPending, setPreviewPending] = useState(false);
  const [previewError, setPreviewError] = useState<GoalErrorKind | null>(null);
  const targetValid = isCanonicalUnsignedDecimal(targetAmount);
  const diff = targetValid ? subtractDecimals(targetAmount, sumOfParts(value.parts)) : null;

  function setMode(mode: GoalScheduleMode) {
    onChange({ mode, parts: value.parts });
  }

  function setPart(index: number, amount: string) {
    const parts = [...value.parts];
    parts[index] = amount;
    onChange({ mode: value.mode, parts });
  }

  async function distributeLinearly() {
    if (!targetValid) return;
    setPreviewPending(true);
    setPreviewError(null);
    const result = await previewGoalSchedule({
      ...authApiOptions(),
      query: { target: targetAmount, mode: 'LINEAR' },
    });
    setPreviewPending(false);
    if (!result.response?.ok || !result.data) {
      setPreviewError(goalErrorKind(goalRequestError(result), true));
      return;
    }
    const sorted = [...result.data.parts].sort((a, b) => a.monthIndex - b.monthIndex);
    onChange({ mode: 'CUSTOM', parts: sorted.map((part) => part.amount) });
  }

  return (
    <div>
      <fieldset className={styles.radios} disabled={disabled}>
        <legend>{t('goals.schedule.legend')}</legend>
        {(['LINEAR', 'CUSTOM'] as GoalScheduleMode[]).map((choice) => (
          <label key={choice}>
            <input
              checked={value.mode === choice}
              name="goal-schedule-mode"
              onChange={() => setMode(choice)}
              type="radio"
            />
            <span>
              {t(choice === 'LINEAR' ? 'goals.schedule.modeLinear' : 'goals.schedule.modeCustom')}
            </span>
          </label>
        ))}
      </fieldset>
      {value.mode === 'CUSTOM' ? (
        <>
          <div className={styles.months}>
            {Array.from({ length: 12 }, (_, index) => (
              <label key={index}>
                <span>{monthName(index, i18n.language)}</span>
                <AmountInput
                  invalid={false}
                  onChange={(amount) => setPart(index, amount)}
                  value={value.parts[index] ?? ''}
                  aria-label={t('goals.schedule.monthLabel', {
                    month: monthName(index, i18n.language),
                  })}
                  disabled={disabled}
                />
              </label>
            ))}
          </div>
          <div className={styles.actions}>
            <button
              className="secondary-action"
              disabled={disabled || !targetValid || previewPending}
              onClick={() => void distributeLinearly()}
              type="button"
            >
              {t(
                previewPending ? 'goals.schedule.distributing' : 'goals.schedule.distributeLinear',
              )}
            </button>
            {diff !== null ? (
              <p
                className={`${styles.status} ${isZeroDecimal(diff) ? styles.statusExact : ''}`}
                role="status"
              >
                {isZeroDecimal(diff)
                  ? t('goals.schedule.exact')
                  : t('goals.schedule.remaining', {
                      amount: formatAmount(diff, assetCode, i18n.language),
                    })}
              </p>
            ) : null}
          </div>
          {previewError ? (
            <p className={styles.error} role="alert">
              {t(`goals.errors.${previewError}`)}
            </p>
          ) : null}
        </>
      ) : null}
    </div>
  );
}
