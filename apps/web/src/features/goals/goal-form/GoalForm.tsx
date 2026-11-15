import type { GoalCreateDefaults } from '@/features/goals/goal-panel/useGoalEditor';
import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import type {
  CreateGoalRequest,
  GoalMetric,
  GoalPeriodicity,
  GoalSummary,
  UpdateGoalRequest,
} from '@cadran/api-client';
import { useId, type FormEvent, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import type { GoalErrorKind, GoalScheduleMismatch } from '@/features/goals/goalError';
import { GoalScheduleEditor } from '@/features/goals/goal-schedule-editor/GoalScheduleEditor';
import { GoalScopeField } from '@/features/goals/goal-scope-field/GoalScopeField';
import { goalMetrics, isStockMetric } from '@/features/goals/goalFormFields';
import { GoalPeriodFields } from '@/features/goals/GoalPeriodFields';
import { GoalToleranceField } from '@/features/goals/goal-tolerance-field/GoalToleranceField';
import { GoalDirectionField } from '@/features/goals/goal-direction-field/GoalDirectionField';
import { GoalValueFields } from '@/features/goals/GoalValueFields';
import { GoalSubmitError } from './GoalSubmitError';
import { goalRequest } from './goalFormRequest';
import { useGoalFormState } from './useGoalFormState';
import styles from './GoalForm.module.css';

interface Props {
  children?: ReactNode;
  baseCurrency: string;
  goal?: GoalSummary;
  /** Period a new goal starts on, when the caller knows the period it is created for. */
  initial?: GoalCreateDefaults;
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
  const { t } = useTranslation();
  const validationId = useId();
  const targetErrorId = useId();
  const form = useGoalFormState(goal, initial);
  const { amount, dispatch, futureGoal, scopeType, showErrors, state } = form;
  const { metric, periodicity, valueType } = state;
  const assetCode = goal ? goal.assetCode : baseCurrency;

  function submit(event: FormEvent) {
    event.preventDefault();
    if (futureGoal) {
      if (!form.labelValid || !goal) {
        form.setShowErrors(true);
        return;
      }
      onSubmit({ label: state.label.trim(), version: goal.version });
      return;
    }
    if (!form.formValid) {
      form.setShowErrors(true);
      return;
    }
    onSubmit(
      goalRequest(state, {
        baseCurrency,
        goal,
        retroactive: form.retroactive,
        scopeType,
        yearlyAmount: form.yearlyAmount,
      }),
    );
  }
  const invalid = showErrors && !form.formValid;
  const flag = (valid: boolean) => (showErrors && !valid ? true : undefined);
  return (
    <ModalForm className={styles.form} noValidate onSubmit={submit}>
      {children}
      {submitError ? (
        <GoalSubmitError assetCode={assetCode} kind={submitError} mismatch={submitMismatch} />
      ) : null}
      <div className={styles.group}>
        <FormGrid>
          <label>
            <span>{t('goals.label')}</span>
            <input
              aria-describedby={flag(form.labelValid) ? validationId : undefined}
              aria-invalid={flag(form.labelValid)}
              data-autofocus
              maxLength={80}
              onChange={(e) => dispatch({ type: 'label', value: e.target.value })}
              value={state.label}
            />
          </label>
          <label>
            <span>{t('goals.metricLabel')}</span>
            <select
              disabled={Boolean(goal)}
              onChange={(e) => dispatch({ type: 'metric', value: e.target.value as GoalMetric })}
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
          <GoalDirectionField
            disabled={Boolean(goal)}
            value={state.direction}
            onChange={(value) => dispatch({ type: 'direction', value })}
          />
        ) : null}
        <GoalScopeField
          goal={goal}
          scope={state.scope}
          scopeType={scopeType}
          scopeValid={form.scopeValid}
          setScope={(value) => dispatch({ type: 'scope', value })}
          showErrors={showErrors}
        />
      </div>
      <div className={styles.group}>
        <GoalPeriodFields
          end={state.end}
          endValid={form.endValid}
          locked={Boolean(goal)}
          noEnd={state.noEnd}
          onEndChange={(value) => dispatch({ type: 'end', value })}
          onNoEndChange={(value) => dispatch({ type: 'noEnd', value })}
          onPeriodicityChange={(value: GoalPeriodicity) =>
            dispatch({ type: 'periodicity', value, defaultStart: form.defaultStart(value) })
          }
          onSingleChange={(value) => dispatch({ type: 'single', value })}
          onStartChange={(value) => dispatch({ type: 'start', value })}
          periodicity={periodicity}
          showErrors={showErrors}
          single={form.single}
          start={state.start}
          startValid={form.startValid}
          validationId={validationId}
        />
      </div>
      <div className={styles.group}>
        <FormGrid>
          {!futureGoal ? (
            <GoalValueFields
              errorId={targetErrorId}
              metric={metric}
              onValueChange={(value) => dispatch({ type: 'value', value })}
              onValueTypeChange={(value) => dispatch({ type: 'valueType', value })}
              periodicity={periodicity}
              showErrors={showErrors}
              valid={form.decimalValid}
              value={state.value}
              valueType={valueType}
            />
          ) : null}
          {!futureGoal ? (
            <GoalToleranceField
              value={state.tolerance}
              onChange={(value) => dispatch({ type: 'tolerance', value })}
              amount={amount}
              assetCode={assetCode}
              invalid={
                (showErrors && !form.toleranceValid) || submitError === 'invalid_goal_tolerance'
              }
            />
          ) : null}
          {goal && !futureGoal ? (
            <label>
              <span>{t('goals.effectiveFrom')}</span>
              <input
                aria-describedby={flag(form.effectiveMonthValid) ? validationId : undefined}
                aria-invalid={flag(form.effectiveMonthValid)}
                max={form.latestEffectiveMonth}
                min={form.minEffectiveMonth}
                onChange={(e) => dispatch({ type: 'effectiveFrom', value: e.target.value })}
                type="month"
                value={state.effectiveFrom}
              />
            </label>
          ) : null}
        </FormGrid>
        {goal && !futureGoal && form.retroactive ? (
          <label>
            <span>{t('goals.correctionReason')}</span>
            <textarea
              aria-describedby={flag(form.reasonValid) ? validationId : undefined}
              aria-invalid={flag(form.reasonValid)}
              maxLength={200}
              minLength={3}
              onChange={(e) => dispatch({ type: 'reason', value: e.target.value })}
              value={state.reason}
            />
            <small>{t('goals.correctionReasonHint')}</small>
          </label>
        ) : null}
        {!futureGoal && flag(form.decimalValid) ? (
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
      {form.yearlyAmount && !futureGoal ? (
        <div className={styles.group}>
          <GoalScheduleEditor
            assetCode={assetCode}
            disabled={pending}
            onChange={(value) => dispatch({ type: 'schedule', value })}
            targetAmount={state.value}
            value={state.schedule}
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
