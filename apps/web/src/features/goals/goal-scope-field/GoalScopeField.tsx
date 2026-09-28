import type { Account, GoalScopeType, GoalSummary } from '@cadran/api-client';
import { listAccounts } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import { authApiOptions } from '@/features/auth/apiOptions';
import styles from '@/features/goals/GoalsPage.module.css';

const AXES = [
  'ESSENTIAL',
  'DISCRETIONARY',
  'FIXED',
  'VARIABLE',
  'PERSONAL',
  'PROFESSIONAL',
] as const;

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
  goal?: GoalSummary;
  scope: string;
  scopeType: GoalScopeType;
  scopeValid: boolean;
  setScope: (value: string) => void;
  showErrors: boolean;
}

/** The scope picker matching the metric's scope type: category, axis, account, or none for workspace. */
export function GoalScopeField({
  goal,
  scope,
  scopeType,
  scopeValid,
  setScope,
  showErrors,
}: Props) {
  const { t } = useTranslation();
  const accountQuery = useQuery({
    enabled: !goal && scopeType === 'ACCOUNT',
    queryKey: ['goal-accounts'],
    queryFn: ({ signal }) => accounts(signal),
    retry: false,
  });
  const flag = (valid: boolean) => (showErrors && !valid ? true : undefined);

  if (scopeType === 'CATEGORY') {
    return goal ? (
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
    );
  }

  if (scopeType === 'AXIS') {
    return (
      <label>
        <span>{t('goals.scope')}</span>
        <select
          aria-invalid={flag(scopeValid)}
          disabled={Boolean(goal)}
          onChange={(e) => setScope(e.target.value)}
          value={scope}
        >
          <option value="">{t('goals.chooseScope')}</option>
          {AXES.map((axis) => (
            <option key={axis} value={axis}>
              {t(`categories.axes.${axis}`)}
            </option>
          ))}
        </select>
      </label>
    );
  }

  if (scopeType === 'ACCOUNT') {
    return (
      <>
        {goal ? (
          <label>
            <span>{t('goals.scope')}</span>
            <input disabled value={goal.scope.label} />
          </label>
        ) : (
          <label>
            <span>{t('goals.scope')}</span>
            <select
              aria-invalid={flag(scopeValid)}
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
        )}
        {!goal && accountQuery.isPending ? <small>{t('goals.accountsLoading')}</small> : null}
        {!goal && accountQuery.isError ? (
          <small className={styles.validation}>{t('goals.accountsError')}</small>
        ) : null}
        {!goal && accountQuery.isSuccess && accountQuery.data.length === 0 ? (
          <small>{t('goals.accountsEmpty')}</small>
        ) : null}
      </>
    );
  }

  return null;
}
