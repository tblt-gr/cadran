import type {
  Account,
  CreateGoalRequest,
  GoalKind,
  GoalMetric,
  GoalScopeType,
  GoalSummary,
  UpdateGoalRequest,
} from '@cadran/api-client';
import { listAccounts } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import { authApiOptions } from '@/features/auth/apiOptions';
import { useWorkspaceTimeZone } from '@/features/auth/useWorkspaceTimeZone';
import { isCanonicalUnsignedDecimal } from '@/lib/decimal';
import { AmountInput } from './AmountInput';
import {
  isValidPositiveRatioPercentage,
  percentageToRatio,
  ratioToPercentage,
} from './goalDecimal';
import type { GoalErrorKind } from './goalError';
import styles from './GoalsPage.module.css';

const AXES = [
  'ESSENTIAL',
  'DISCRETIONARY',
  'FIXED',
  'VARIABLE',
  'PERSONAL',
  'PROFESSIONAL',
] as const;
const annualMetrics: GoalMetric[] = [
  'NET_SAVINGS_TRANSFERS',
  'BUDGET_SURPLUS',
  'CATEGORY_SPENDING',
  'AXIS_SPENDING',
  'ACCOUNT_CONTRIBUTIONS',
];
const rollingMetrics: GoalMetric[] = [
  'NET_SAVINGS_TRANSFERS',
  'CATEGORY_SPENDING',
  'AXIS_SPENDING',
];
function scopeFor(metric: GoalMetric): GoalScopeType {
  return metric === 'CATEGORY_SPENDING'
    ? 'CATEGORY'
    : metric === 'AXIS_SPENDING'
      ? 'AXIS'
      : metric === 'ACCOUNT_CONTRIBUTIONS'
        ? 'ACCOUNT'
        : 'WORKSPACE';
}
function accounts(signal?: AbortSignal) {
  return listAccounts({
    ...authApiOptions(),
    query: { includeArchived: false, includeClosed: false, page: 1, perPage: 100 },
    signal,
  }).then((result) => {
    if (!result.response?.ok || !result.data) throw new Error('goals-accounts');
    return result.data.items as Account[];
  });
}
interface Props {
  baseCurrency: string;
  goal?: GoalSummary;
  pending: boolean;
  submitError: GoalErrorKind | null;
  onSubmit: (request: CreateGoalRequest | UpdateGoalRequest) => void;
}
function currentMonthIn(timeZone: string): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
  })
    .format(new Date())
    .slice(0, 7);
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
  const scopeType = scopeFor(metric);
  const accountQuery = useQuery({
    enabled: !goal && scopeType === 'ACCOUNT',
    queryKey: ['goal-accounts'],
    queryFn: ({ signal }) => accounts(signal),
    retry: false,
  });
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
  function changeKind(next: GoalKind) {
    setKind(next);
    const nextMetric = (next === 'ANNUAL' ? annualMetrics : rollingMetrics).includes(metric)
      ? metric
      : 'NET_SAVINGS_TRANSFERS';
    setMetric(nextMetric);
    setScope('');
  }
  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (
      !labelValid ||
      !decimalValid ||
      !scopeValid ||
      (kind === 'ANNUAL' && !yearValid) ||
      (kind === 'ROLLING_RATIO' && !/^\d{4}-\d{2}$/.test(startMonth))
    ) {
      setShowErrors(true);
      return;
    }
    if (goal) {
      onSubmit({
        label: label.trim(),
        version: goal.version,
        ...(goal.kind === 'ANNUAL'
          ? { targetAmount: { value, assetCode: goal.assetCode } }
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
        ? { year: Number(year), targetAmount: { value, assetCode: baseCurrency } }
        : { ratio: percentageToRatio(value)!, startMonth }),
    });
  }
  const invalid =
    showErrors &&
    (!labelValid || !decimalValid || !scopeValid || (kind === 'ANNUAL' && !yearValid));
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
      {scopeType === 'CATEGORY' ? (
        goal ? (
          <label>
            <span>{t('goals.scope')}</span>
            <input disabled value={goal.scope.label} />
          </label>
        ) : (
          <CategoryPicker
            error={showErrors && !scopeValid ? t('goals.validation.scope') : null}
            label={t('goals.scope')}
            onChange={setScope}
            type="EXPENSE"
            value={scope}
          />
        )
      ) : null}
      {scopeType === 'AXIS' ? (
        <label>
          <span>{t('goals.scope')}</span>
          <select disabled={Boolean(goal)} onChange={(e) => setScope(e.target.value)} value={scope}>
            <option value="">{t('goals.chooseScope')}</option>
            {AXES.map((axis) => (
              <option key={axis} value={axis}>
                {t(`categories.axes.${axis}`)}
              </option>
            ))}
          </select>
        </label>
      ) : null}
      {scopeType === 'ACCOUNT' ? (
        goal ? (
          <label>
            <span>{t('goals.scope')}</span>
            <input disabled value={goal.scope.label} />
          </label>
        ) : (
          <label>
            <span>{t('goals.scope')}</span>
            <select
              disabled={Boolean(goal) || accountQuery.isPending}
              onChange={(e) => setScope(e.target.value)}
              value={scope}
            >
              <option value="">{t('goals.chooseScope')}</option>
              {(accountQuery.data ?? []).map((account) => (
                <option key={account.id} value={account.id}>
                  {account.label}
                </option>
              ))}
            </select>
          </label>
        )
      ) : null}
      {!goal && scopeType === 'ACCOUNT' && accountQuery.isPending ? (
        <small>{t('goals.accountsLoading')}</small>
      ) : null}
      {!goal && scopeType === 'ACCOUNT' && accountQuery.isError ? (
        <small className={styles.validation}>{t('goals.accountsError')}</small>
      ) : null}
      {!goal &&
      scopeType === 'ACCOUNT' &&
      accountQuery.isSuccess &&
      accountQuery.data.length === 0 ? (
        <small>{t('goals.accountsEmpty')}</small>
      ) : null}
      {kind === 'ANNUAL' ? (
        <label>
          <span>{t('goals.year')}</span>
          <input
            aria-invalid={showErrors && !yearValid ? true : undefined}
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
          <AmountInput invalid={invalid} onChange={setValue} value={value} />
        ) : (
          <input
            aria-invalid={invalid ? true : undefined}
            inputMode="decimal"
            onChange={(e) => setValue(e.target.value)}
            value={value}
          />
        )}
        {kind === 'ROLLING_RATIO' ? <small>{t('goals.ratioHint')}</small> : null}
      </label>
      {invalid ? <small className={styles.validation}>{t('goals.validation.form')}</small> : null}
      <div className={styles.formActions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'goals.saving' : 'goals.save')}
        </button>
      </div>
    </form>
  );
}
