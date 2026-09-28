import type {
  CreateGoalRequest,
  GoalKind,
  GoalMetric,
  GoalScheduleInput,
  GoalSummary,
  UpdateGoalRequest,
} from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { isCanonicalUnsignedDecimal } from '@/lib/decimal';
import { AmountInput } from './AmountInput';
import {
  isValidPositiveRatioPercentage,
  percentageToRatio,
  ratioToPercentage,
} from './goalDecimal';
import type { GoalErrorKind } from './goalError';
import {
  GoalScheduleEditor,
  type GoalScheduleValue,
} from './goal-schedule-editor/GoalScheduleEditor';
import { GoalScopeField } from './goal-scope-field/GoalScopeField';
import {
  annualMetrics,
  currentMonthIn,
  initialSchedule,
  rollingMetrics,
  scopeFor,
} from './goalFormFields';
import styles from './GoalsPage.module.css';

interface Props {
  baseCurrency: string;
  goal?: GoalSummary;
  pending: boolean;
  submitError: GoalErrorKind | null;
  onSubmit: (request: CreateGoalRequest | UpdateGoalRequest) => void;
}
export function GoalForm({ baseCurrency, goal, pending, submitError, onSubmit }: Props) {
  const { t } = useTranslation();
  const timeZone = useWorkspaceTimeZone();
  const version = goal?.currentVersion;
  const [label, setLabel] = useState(goal?.label ?? '');
  const [kind, setKind] = useState<GoalKind>(goal?.kind ?? 'ANNUAL');
  const [metric, setMetric] = useState<GoalMetric>(goal?.metric ?? 'NET_SAVINGS_TRANSFERS');
  const [scope, setScope] = useState(goal?.scope.id ?? goal?.scope.axis ?? '');
  const [year, setYear] = useState(String(version?.year ?? new Date().getFullYear()));
  const [startMonth, setStartMonth] = useState(version?.effectiveFrom ?? currentMonthIn(timeZone));
  const [value, setValue] = useState(
    version?.targetAmount ?? (version?.ratio ? (ratioToPercentage(version.ratio) ?? '') : ''),
  );
  const [showErrors, setShowErrors] = useState(false);
  const [schedule, setSchedule] = useState<GoalScheduleValue>(() => initialSchedule(goal));
  const scopeType = scopeFor(metric);
  const labelValid = label.trim().length >= 1 && [...label.trim()].length <= 80;
  const amountValid =
    isCanonicalUnsignedDecimal(value) &&
    !/^0(?:\.0+)?$/.test(value) &&
    /^\d+(?:\.\d{1,8})?$/.test(value);
  const ratioValid = isValidPositiveRatioPercentage(value);
  const decimalValid = kind === 'ANNUAL' ? amountValid : ratioValid;
  const scopeValid = scopeType === 'WORKSPACE' || scope !== '';
  const yearValid =
    /^\d{4}$/.test(year) && Number(year) >= 1900 && Number(year) <= new Date().getFullYear() + 5;
  const periodValid = kind === 'ANNUAL' ? yearValid : /^\d{4}-\d{2}$/.test(startMonth);
  const scheduleValid =
    kind !== 'ANNUAL' ||
    schedule.mode === 'LINEAR' ||
    schedule.parts.every((part) => isCanonicalUnsignedDecimal(part));
  function changeKind(next: GoalKind) {
    setKind(next);
    const nextMetric = (next === 'ANNUAL' ? annualMetrics : rollingMetrics).includes(metric)
      ? metric
      : 'NET_SAVINGS_TRANSFERS';
    setMetric(nextMetric);
    setScope('');
  }
  const scheduleField: GoalScheduleInput =
    schedule.mode === 'CUSTOM'
      ? { mode: 'CUSTOM', parts: schedule.parts as GoalScheduleInput['parts'] }
      : { mode: 'LINEAR' };
  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!labelValid || !decimalValid || !scopeValid || !periodValid || !scheduleValid) {
      setShowErrors(true);
      return;
    }
    if (goal) {
      onSubmit({
        label: label.trim(),
        version: goal.version,
        ...(goal.kind === 'ANNUAL'
          ? { targetAmount: { value, assetCode: goal.assetCode }, schedule: scheduleField }
          : { ratio: percentageToRatio(value)! }),
      });
      return;
    }
    onSubmit({
      label: label.trim(),
      kind,
      metric,
      scope: { type: scopeType, ref: scopeType === 'WORKSPACE' ? null : scope },
      ...(kind === 'ANNUAL'
        ? {
            year: Number(year),
            targetAmount: { value, assetCode: baseCurrency },
            schedule: scheduleField,
          }
        : { ratio: percentageToRatio(value)!, startMonth }),
    });
  }
  const invalid =
    showErrors && (!labelValid || !decimalValid || !scopeValid || !periodValid || !scheduleValid);
  const flag = (valid: boolean) => (showErrors && !valid ? true : undefined);
  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`goals.errors.${submitError}`)}
        </p>
      ) : null}
      <label>
        <span>{t('goals.label')}</span>
        <input
          aria-invalid={flag(labelValid)}
          data-autofocus
          maxLength={80}
          onChange={(e) => setLabel(e.target.value)}
          value={label}
        />
      </label>
      <fieldset className={styles.radios} disabled={Boolean(goal)}>
        <legend>{t('goals.kind')}</legend>
        {(['ANNUAL', 'ROLLING_RATIO'] as GoalKind[]).map((choice) => (
          <label key={choice}>
            <input
              checked={kind === choice}
              name="kind"
              onChange={() => changeKind(choice)}
              type="radio"
            />
            <span>{t(`goals.kinds.${choice}`)}</span>
          </label>
        ))}
      </fieldset>
      <label>
        <span>{t('goals.metric')}</span>
        <select
          disabled={Boolean(goal)}
          onChange={(e) => {
            setMetric(e.target.value as GoalMetric);
            setScope('');
          }}
          value={metric}
        >
          {(kind === 'ANNUAL' ? annualMetrics : rollingMetrics).map((choice) => (
            <option key={choice} value={choice}>
              {t(`goals.metrics.${choice}`)}
            </option>
          ))}
        </select>
      </label>
      <GoalScopeField
        goal={goal}
        scope={scope}
        scopeType={scopeType}
        scopeValid={scopeValid}
        setScope={setScope}
        showErrors={showErrors}
      />
      {kind === 'ANNUAL' ? (
        <label>
          <span>{t('goals.year')}</span>
          <input
            aria-invalid={flag(yearValid)}
            disabled={Boolean(goal)}
            inputMode="numeric"
            onChange={(e) => setYear(e.target.value)}
            value={year}
          />
        </label>
      ) : (
        <label>
          <span>{t('goals.startMonth')}</span>
          <input
            aria-invalid={flag(periodValid)}
            disabled={Boolean(goal)}
            onChange={(e) => setStartMonth(e.target.value)}
            type="month"
            value={startMonth}
          />
        </label>
      )}
      <label>
        <span>{t(kind === 'ANNUAL' ? 'goals.targetAmount' : 'goals.ratio')}</span>
        {kind === 'ANNUAL' ? (
          <AmountInput invalid={Boolean(flag(decimalValid))} onChange={setValue} value={value} />
        ) : (
          <input
            aria-invalid={flag(decimalValid)}
            inputMode="decimal"
            onChange={(e) => setValue(e.target.value)}
            value={value}
          />
        )}
        {kind === 'ROLLING_RATIO' ? <small>{t('goals.ratioHint')}</small> : null}
      </label>
      {kind === 'ANNUAL' ? (
        <GoalScheduleEditor
          assetCode={goal ? goal.assetCode : baseCurrency}
          disabled={pending}
          onChange={setSchedule}
          targetAmount={value}
          value={schedule}
        />
      ) : null}
      {invalid ? (
        <p className={styles.alert} role="alert">
          {t('goals.validation.form')}
        </p>
      ) : null}
      <div className={styles.formActions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'goals.saving' : 'goals.save')}
        </button>
      </div>
    </form>
  );
}
