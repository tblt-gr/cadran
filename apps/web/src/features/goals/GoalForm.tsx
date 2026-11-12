import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import type {
  CreateGoalRequest,
  GoalMetric,
  GoalDirection,
  GoalPeriodicity,
  GoalScheduleInput,
  GoalSummary,
  GoalValueType,
  UpdateGoalRequest,
} from '@cadran/api-client';
import { useId, useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { formatAmount, isCanonicalUnsignedDecimal } from '@/lib/decimal';
import {
  isValidPositiveRatioPercentage,
  isValidRatePercentage,
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
  currentMonthIn,
  firstGoalMonth,
  goalMetrics,
  initialSchedule,
  isStockMetric,
  lastGoalMonth,
  MAX_CORRECTION_MONTHS,
  monthsBefore,
  scopeFor,
  valueTypesFor,
} from './goalFormFields';
import { GoalPeriodFields } from './GoalPeriodFields';
import { GoalToleranceField } from './goal-tolerance-field/GoalToleranceField';
import { displayTolerance, toleranceInput, validTolerance } from './goalTolerance';
import { GoalDirectionField } from './goal-direction-field/GoalDirectionField';
import { GoalValueFields } from './GoalValueFields';
import styles from './GoalForm.module.css';

interface Props {
  children?: ReactNode;
  baseCurrency: string;
  goal?: GoalSummary;
  /** Period a new goal starts on, when the caller knows the period it is created for. */
  initial?: { periodicity: GoalPeriodicity; start: string };
  pending: boolean;
  submitError: GoalErrorKind | null;
  submitMismatch: GoalScheduleMismatch | null;
  onSubmit: (request: CreateGoalRequest | UpdateGoalRequest) => void;
}
export function GoalForm({
  children,
  baseCurrency,
  goal,
  initial,
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
  const currentYear = Number(workspaceMonth.slice(0, 4));
  const defaultStart = (next: GoalPeriodicity) =>
    next === 'MONTHLY' ? workspaceMonth : workspaceMonth.slice(0, 4);
  const [label, setLabel] = useState(goal?.label ?? '');
  const [periodicity, setPeriodicity] = useState<GoalPeriodicity>(
    goal?.periodicity ?? initial?.periodicity ?? 'MONTHLY',
  );
  const [metric, setMetric] = useState<GoalMetric>(goal?.metric ?? 'NET_SAVINGS_TRANSFERS');
  const [direction, setDirection] = useState<GoalDirection>(goal?.direction ?? 'MINIMUM');
  const [scope, setScope] = useState(goal?.scope.id ?? goal?.scope.axis ?? '');
  const [start, setStart] = useState(
    goal?.startPeriod ?? initial?.start ?? defaultStart(periodicity),
  );
  const [noEnd, setNoEnd] = useState(goal ? goal.endPeriod === null : true);
  const [end, setEnd] = useState(goal?.endPeriod ?? '');
  const [valueType, setValueType] = useState<GoalValueType>(version?.valueType ?? 'AMOUNT');
  const monthly = periodicity === 'MONTHLY';
  const amount = valueType === 'AMOUNT';
  const single = !noEnd && end !== '' && end === start;
  const firstMonth = goal ? firstGoalMonth(goal.periodicity, goal.startPeriod) : workspaceMonth;
  const lastMonth = goal ? lastGoalMonth(goal.periodicity, goal.endPeriod) : null;
  const earliestEffectiveMonth = firstMonth;
  const latestEffectiveMonth =
    lastMonth !== null && lastMonth < workspaceMonth ? lastMonth : workspaceMonth;
  // A correction reaches back at most this many months, the current one included.
  const oldestCorrectionMonth = monthsBefore(workspaceMonth, MAX_CORRECTION_MONTHS - 1);
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
    version?.targetAmount ??
      (version?.ratio != null ? (ratioToPercentage(version.ratio) ?? '') : ''),
  );
  const [tolerance, setTolerance] = useState(() =>
    version ? displayTolerance(version.tolerance ?? '0', version.valueType) : '',
  );
  const toleranceValid = validTolerance(tolerance, value, valueType);
  const [showErrors, setShowErrors] = useState(false);
  const [schedule, setSchedule] = useState<GoalScheduleValue>(() => initialSchedule(goal));
  const scopeType = scopeFor(metric);
  const labelValid = label.trim().length >= 1 && [...label.trim()].length <= 80;
  const amountValid =
    isCanonicalUnsignedDecimal(value) &&
    !/^0(?:\.0+)?$/.test(value) &&
    /^\d+(?:\.\d{1,8})?$/.test(value);
  const decimalValid = amount
    ? amountValid
    : valueType === 'RATE'
      ? isValidRatePercentage(value)
      : isValidPositiveRatioPercentage(value);
  const scopeValid = scopeType === 'WORKSPACE' || scope !== '';
  const periodFormat = (period: string) =>
    monthly
      ? /^\d{4}-(0[1-9]|1[0-2])$/.test(period)
      : /^\d{4}$/.test(period) && Number(period) >= 1900 && Number(period) <= currentYear + 5;
  const startValid = periodFormat(start);
  const endValid = noEnd || (periodFormat(end) && end >= start);
  const periodValid = startValid && endValid;
  const yearlyAmount = periodicity === 'YEARLY' && amount && !isStockMetric(metric);
  const scheduleValid =
    !yearlyAmount ||
    schedule.mode === 'LINEAR' ||
    schedule.parts.every((part) => isCanonicalUnsignedDecimal(part));
  const effectiveMonthValid =
    !goal ||
    (/^\d{4}-(0[1-9]|1[0-2])$/.test(effectiveFrom) &&
      effectiveFrom >= minEffectiveMonth &&
      effectiveFrom <= latestEffectiveMonth);
  const retroactive = Boolean(goal && effectiveFrom < workspaceMonth);
  const reasonValid = !retroactive || (reason.trim().length >= 3 && reason.trim().length <= 200);
  function changeValueType(next: GoalValueType) {
    // An exact amount and a percentage are not interchangeable: never carry one into the other.
    if (amount !== (next === 'AMOUNT')) {
      setValue('');
      setTolerance('');
    }
    setValueType(next);
  }
  function keepValueTypeAllowed(nextMetric: GoalMetric, nextPeriodicity: GoalPeriodicity) {
    const allowed = valueTypesFor(nextMetric, nextPeriodicity);
    if (!allowed.includes(valueType)) changeValueType(allowed[0]!);
  }
  function changeMetric(next: GoalMetric) {
    setMetric(next);
    setScope('');
    keepValueTypeAllowed(next, periodicity);
  }
  function changePeriodicity(next: GoalPeriodicity) {
    const nextStart = defaultStart(next);
    setPeriodicity(next);
    setStart(nextStart);
    setEnd(noEnd ? '' : nextStart);
    keepValueTypeAllowed(metric, next);
  }
  function changeStart(next: string) {
    setStart(next);
    if (single) setEnd(next);
  }
  function changeNoEnd(next: boolean) {
    setNoEnd(next);
    setEnd(next ? '' : start);
  }
  function changeSingle(next: boolean) {
    setNoEnd(!next);
    setEnd(next ? start : '');
  }
  const scheduleField: GoalScheduleInput =
    schedule.mode === 'CUSTOM'
      ? { mode: 'CUSTOM', parts: schedule.parts as GoalScheduleInput['parts'] }
      : { mode: 'LINEAR' };
  const formValid =
    labelValid &&
    decimalValid &&
    toleranceValid &&
    scopeValid &&
    periodValid &&
    scheduleValid &&
    effectiveMonthValid &&
    reasonValid;
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
    if (!formValid) {
      setShowErrors(true);
      return;
    }
    const target = amount
      ? { targetAmount: { value, assetCode: goal ? goal.assetCode : baseCurrency } }
      : { ratio: percentageToRatio(value)! };
    if (goal) {
      onSubmit({
        label: label.trim(),
        version: goal.version,
        valueType,
        effectiveFrom,
        reason: retroactive ? reason.trim() : null,
        ...target,
        tolerance: toleranceInput(tolerance, valueType)!,
        ...(yearlyAmount ? { schedule: scheduleField } : {}),
      });
      return;
    }
    onSubmit({
      label: label.trim(),
      periodicity,
      metric,
      ...(isStockMetric(metric) ? { direction } : {}),
      scope: { type: scopeType, ref: scopeType === 'WORKSPACE' ? null : scope },
      startPeriod: start,
      endPeriod: noEnd ? null : end,
      valueType,
      ...target,
      tolerance: toleranceInput(tolerance, valueType)!,
      ...(yearlyAmount ? { schedule: scheduleField } : {}),
    });
  }
  const invalid = showErrors && !formValid;
  const flag = (valid: boolean) => (showErrors && !valid ? true : undefined);
  return (
    <ModalForm className={styles.form} noValidate onSubmit={submit}>
      {children}
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
      <div className={styles.group}>
        <FormGrid>
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
          <label>
            <span>{t('goals.metricLabel')}</span>
            <select
              disabled={Boolean(goal)}
              onChange={(e) => changeMetric(e.target.value as GoalMetric)}
              value={metric}
            >
              {goalMetrics.map((choice) => (
                <option key={choice} value={choice}>
                  {t(`goals.metric.${choice}`)}
                </option>
              ))}
            </select>
          </label>
        </FormGrid>
        {isStockMetric(metric) ? (
          <GoalDirectionField disabled={Boolean(goal)} value={direction} onChange={setDirection} />
        ) : null}
        <GoalScopeField
          goal={goal}
          scope={scope}
          scopeType={scopeType}
          scopeValid={scopeValid}
          setScope={setScope}
          showErrors={showErrors}
        />
      </div>
      <div className={styles.group}>
        <GoalPeriodFields
          end={end}
          endValid={endValid}
          locked={Boolean(goal)}
          noEnd={noEnd}
          onEndChange={setEnd}
          onNoEndChange={changeNoEnd}
          onPeriodicityChange={changePeriodicity}
          onSingleChange={changeSingle}
          onStartChange={changeStart}
          periodicity={periodicity}
          showErrors={showErrors}
          single={single}
          start={start}
          startValid={startValid}
          validationId={validationId}
        />
      </div>
      <div className={styles.group}>
        <FormGrid>
          {!futureGoal ? (
            <GoalValueFields
              errorId={targetErrorId}
              metric={metric}
              onValueChange={setValue}
              onValueTypeChange={changeValueType}
              periodicity={periodicity}
              showErrors={showErrors}
              valid={decimalValid}
              value={value}
              valueType={valueType}
            />
          ) : null}
          {!futureGoal ? (
            <GoalToleranceField
              value={tolerance}
              onChange={setTolerance}
              amount={amount}
              assetCode={goal?.assetCode ?? baseCurrency}
              invalid={(showErrors && !toleranceValid) || submitError === 'invalid_goal_tolerance'}
            />
          ) : null}
          {goal && !futureGoal ? (
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
          ) : null}
        </FormGrid>
        {goal && !futureGoal && retroactive ? (
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
        {!futureGoal && flag(decimalValid) ? (
          <p className={styles.alert} id={targetErrorId} role="alert">
            {t(
              amount
                ? 'goals.validation.targetAmount'
                : valueType === 'RATE'
                  ? 'goals.validation.rate'
                  : 'goals.validation.ratio',
            )}
          </p>
        ) : null}
      </div>
      {yearlyAmount && !futureGoal ? (
        <div className={styles.group}>
          <GoalScheduleEditor
            assetCode={goal ? goal.assetCode : baseCurrency}
            disabled={pending}
            onChange={setSchedule}
            targetAmount={value}
            value={schedule}
          />
        </div>
      ) : null}
      {invalid ? (
        <p className={styles.alert} id={validationId} role="alert">
          {t('goals.validation.form')}
        </p>
      ) : null}
      <ModalActions>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'goals.saving' : 'goals.save')}
        </button>
      </ModalActions>
    </ModalForm>
  );
}
