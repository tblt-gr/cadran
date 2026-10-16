import type {
  CreateGoalRequest,
  GoalKind,
  GoalMetric,
  GoalScheduleInput,
  GoalSummary,
  UpdateGoalRequest,
} from '@cadran/api-client';
import { useId, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { formatAmount, isCanonicalUnsignedDecimal } from '@/lib/decimal';
import { AmountInput } from './AmountInput';
import {
  isValidPositiveRatioPercentage,
  percentageToRatio,
  ratioToPercentage,
} from './goalDecimal';
import type { GoalErrorKind, GoalScheduleMismatch } from './goalError';
import {
  GoalScheduleEditor,
  type GoalScheduleValue,
} from './goal-schedule-editor/GoalScheduleEditor';
import { GoalScopeField } from './goal-scope-field/GoalScopeField';
import {
  annualMetrics,
  currentMonthIn,
  initialSchedule,
  MAX_CORRECTION_MONTHS,
  monthsBefore,
  rollingMetrics,
  scopeFor,
} from './goalFormFields';
import styles from './GoalsPage.module.css';

interface Props {
  baseCurrency: string;
  firstEffectiveMonth?: string;
  goal?: GoalSummary;
  pending: boolean;
  submitError: GoalErrorKind | null;
  submitMismatch: GoalScheduleMismatch | null;
  onSubmit: (request: CreateGoalRequest | UpdateGoalRequest) => void;
}
export function GoalForm({
  baseCurrency,
  goal,
  firstEffectiveMonth,
  pending,
  submitError,
  submitMismatch,
  onSubmit,
}: Props) {
  const { t, i18n } = useTranslation();
  const validationId = useId();
  const targetErrorId = useId();
  const timeZone = useWorkspaceTimeZone();
  const version = goal?.currentVersion;
  const workspaceMonth = currentMonthIn(timeZone);
  const [label, setLabel] = useState(goal?.label ?? '');
  const [kind, setKind] = useState<GoalKind>(goal?.kind ?? 'ANNUAL');
  const [metric, setMetric] = useState<GoalMetric>(goal?.metric ?? 'NET_SAVINGS_TRANSFERS');
  const [scope, setScope] = useState(goal?.scope.id ?? goal?.scope.axis ?? '');
  const [year, setYear] = useState(String(version?.year ?? new Date().getFullYear()));
  const [startMonth, setStartMonth] = useState(version?.effectiveFrom ?? currentMonthIn(timeZone));
  const annualYear = version?.year ?? Number(year);
  const earliestEffectiveMonth =
    goal?.kind === 'ANNUAL'
      ? `${annualYear}-01`
      : (firstEffectiveMonth ?? version?.effectiveFrom ?? workspaceMonth);
  const latestEffectiveMonth =
    goal?.kind === 'ANNUAL'
      ? `${annualYear}-${annualYear < Number(workspaceMonth.slice(0, 4)) ? '12' : workspaceMonth.slice(5)}`
      : workspaceMonth;
  // A rolling correction reaches back at most this many months, the current one included.
  const oldestCorrectionMonth =
    goal?.kind === 'ANNUAL'
      ? earliestEffectiveMonth
      : monthsBefore(workspaceMonth, MAX_CORRECTION_MONTHS - 1);
  const minEffectiveMonth =
    oldestCorrectionMonth > earliestEffectiveMonth ? oldestCorrectionMonth : earliestEffectiveMonth;
  const futureGoal = Boolean(goal && earliestEffectiveMonth > workspaceMonth);
  const defaultEffectiveMonth =
    workspaceMonth >= earliestEffectiveMonth && workspaceMonth <= latestEffectiveMonth
      ? workspaceMonth
      : latestEffectiveMonth;
  const [effectiveFrom, setEffectiveFrom] = useState(defaultEffectiveMonth);
  const [reason, setReason] = useState('');
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
  const effectiveMonthValid =
    !goal ||
    (/^\d{4}-(0[1-9]|1[0-2])$/.test(effectiveFrom) &&
      effectiveFrom >= minEffectiveMonth &&
      effectiveFrom <= latestEffectiveMonth);
  const retroactive = Boolean(goal && effectiveFrom < workspaceMonth);
  const reasonValid = !retroactive || (reason.trim().length >= 3 && reason.trim().length <= 200);
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
    if (futureGoal) {
      if (!labelValid || !goal) {
        setShowErrors(true);
        return;
      }
      onSubmit({ label: label.trim(), version: goal.version });
      return;
    }
    if (
      !labelValid ||
      !decimalValid ||
      !scopeValid ||
      !periodValid ||
      !scheduleValid ||
      !effectiveMonthValid ||
      !reasonValid
    ) {
      setShowErrors(true);
      return;
    }
    if (goal) {
      onSubmit({
        label: label.trim(),
        version: goal.version,
        effectiveFrom,
        reason: retroactive ? reason.trim() : null,
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
    showErrors &&
    (!labelValid ||
      !decimalValid ||
      !scopeValid ||
      !periodValid ||
      !scheduleValid ||
      !effectiveMonthValid ||
      !reasonValid);
  const flag = (valid: boolean) => (showErrors && !valid ? true : undefined);
  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`goals.errors.${submitError}`)}
          {submitError === 'schedule_sum_mismatch' && submitMismatch ? (
            <>
              {' '}
              {t('goals.schedule.serverMismatch', {
                difference: formatAmount(
                  submitMismatch.difference,
                  goal?.assetCode ?? baseCurrency,
                  i18n.language,
                ),
              })}
            </>
          ) : null}
        </p>
      ) : null}
      <label>
        <span>{t('goals.label')}</span>
        <input
          aria-describedby={flag(labelValid) ? validationId : undefined}
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
      {goal && !futureGoal ? (
        <>
          <label>
            <span>{t('goals.effectiveFrom')}</span>
            <input
              aria-describedby={flag(effectiveMonthValid) ? validationId : undefined}
              aria-invalid={flag(effectiveMonthValid)}
              max={latestEffectiveMonth}
              min={minEffectiveMonth}
              onChange={(e) => setEffectiveFrom(e.target.value)}
              type="month"
              value={effectiveFrom}
            />
          </label>
          {retroactive ? (
            <label>
              <span>{t('goals.correctionReason')}</span>
              <textarea
                aria-describedby={flag(reasonValid) ? validationId : undefined}
                aria-invalid={flag(reasonValid)}
                maxLength={200}
                minLength={3}
                onChange={(e) => setReason(e.target.value)}
                value={reason}
              />
              <small>{t('goals.correctionReasonHint')}</small>
            </label>
          ) : null}
        </>
      ) : null}
      {kind === 'ANNUAL' ? (
        <label>
          <span>{t('goals.year')}</span>
          <input
            aria-describedby={flag(yearValid) ? validationId : undefined}
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
            aria-describedby={flag(periodValid) ? validationId : undefined}
            aria-invalid={flag(periodValid)}
            disabled={Boolean(goal)}
            onChange={(e) => setStartMonth(e.target.value)}
            type="month"
            value={startMonth}
          />
        </label>
      )}
      {!futureGoal ? (
        <label>
          <span>{t(kind === 'ANNUAL' ? 'goals.targetAmount' : 'goals.ratio')}</span>
          {kind === 'ANNUAL' ? (
            <AmountInput
              aria-label={t('goals.targetAmount')}
              aria-describedby={flag(decimalValid) ? targetErrorId : undefined}
              invalid={Boolean(flag(decimalValid))}
              onChange={setValue}
              value={value}
            />
          ) : (
            <input
              aria-label={t('goals.ratio')}
              aria-describedby={flag(decimalValid) ? targetErrorId : undefined}
              aria-invalid={flag(decimalValid)}
              inputMode="decimal"
              onChange={(e) => setValue(e.target.value)}
              value={value}
            />
          )}
          {kind === 'ROLLING_RATIO' ? <small>{t('goals.ratioHint')}</small> : null}
        </label>
      ) : null}
      {!futureGoal && flag(decimalValid) ? (
        <p className={styles.alert} id={targetErrorId} role="alert">
          {t(kind === 'ANNUAL' ? 'goals.validation.targetAmount' : 'goals.validation.ratio')}
        </p>
      ) : null}
      {kind === 'ANNUAL' && !futureGoal ? (
        <GoalScheduleEditor
          assetCode={goal ? goal.assetCode : baseCurrency}
          disabled={pending}
          onChange={setSchedule}
          targetAmount={value}
          value={schedule}
        />
      ) : null}
      {invalid ? (
        <p className={styles.alert} id={validationId} role="alert">
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
