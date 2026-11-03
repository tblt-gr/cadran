import type { Account, AnalyticAxes } from '@cadran/api-client';
import { useState, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { FormSection } from '@/components/ui/form-layout/FormSection';
import formStyles from '@/features/categories/rules/rule-form/RuleForm.module.css';
import { AccountScopeSelect } from './account-scope-select/AccountScopeSelect';
import styles from './RuleAdvancedFields.module.css';

type Axis = AnalyticAxes[number];

const AXES: Axis[] = [
  'ESSENTIAL',
  'DISCRETIONARY',
  'FIXED',
  'VARIABLE',
  'PERSONAL',
  'PROFESSIONAL',
];
const DEFAULT_PRIORITY = '100';

interface RuleAdvancedFieldsProps {
  children?: ReactNode;
  periodFilled?: boolean;
  periodInvalid?: boolean;
  accountScope: string[];
  accounts: Account[];
  onClearAccounts: () => void;
  onCounterpartyChange: (value: string) => void;
  onPriorityChange: (value: string) => void;
  onToggleAccount: (id: string) => void;
  onToggleAxis: (axis: Axis) => void;
  priority: string;
  /** Already gated on the form having tried to submit. */
  priorityInvalid: boolean;
  targetAxes: Axis[];
  targetCounterparty: string;
}

/**
 * Settings a rule works without: evaluation order, account scope and what it
 * fills besides the category. Folded by default; the summary names those
 * holding a non-default value so nothing is hidden without a trace, and an
 * invalid priority opens the section.
 */
export function RuleAdvancedFields({
  children,
  periodFilled,
  periodInvalid,
  accountScope,
  accounts,
  onClearAccounts,
  onCounterpartyChange,
  onPriorityChange,
  onToggleAccount,
  onToggleAxis,
  priority,
  priorityInvalid,
  targetAxes,
  targetCounterparty,
}: RuleAdvancedFieldsProps) {
  const { t } = useTranslation();
  const [open, setOpen] = useState(
    Boolean(
      periodFilled ||
      accountScope.length ||
      priority !== DEFAULT_PRIORITY ||
      targetAxes.length ||
      targetCounterparty,
    ),
  );
  const filled = [
    priority !== DEFAULT_PRIORITY
      ? t('categorizationRules.fields.priorityValue', { value: priority })
      : null,
    accountScope.length > 0
      ? t('categorizationRules.list.accountCount', { count: accountScope.length })
      : null,
    targetCounterparty.trim() !== '' ? t('categorizationRules.fields.counterparty') : null,
    targetAxes.length > 0 ? t('categorizationRules.fields.axes') : null,
  ].filter((value) => value !== null);

  return (
    <FormSection
      meta={filled.length > 0 ? filled.join(' · ') : undefined}
      onToggle={setOpen}
      open={open || priorityInvalid || Boolean(periodInvalid)}
      title={t('transactions.form.advanced')}
    >
      {children}
      <AccountScopeSelect
        accounts={accounts}
        onClear={onClearAccounts}
        onToggle={onToggleAccount}
        selected={accountScope}
      />
      <label className={formStyles.field}>
        <span>{t('categorizationRules.fields.priority')}</span>
        <input
          aria-invalid={priorityInvalid ? true : undefined}
          inputMode="numeric"
          maxLength={3}
          onChange={(event) => onPriorityChange(event.target.value)}
          value={priority}
        />
        <small className={priorityInvalid ? formStyles.error : formStyles.hint}>
          {t(
            priorityInvalid
              ? 'categorizationRules.validation.priority'
              : 'categorizationRules.fields.priorityHint',
          )}
        </small>
      </label>
      <label className={formStyles.field}>
        <span>{t('categorizationRules.fields.counterparty')}</span>
        <input
          maxLength={80}
          onChange={(event) => onCounterpartyChange(event.target.value)}
          value={targetCounterparty}
        />
        <small className={formStyles.hint}>
          {t('categorizationRules.fields.counterpartyHint')}
        </small>
      </label>
      <fieldset data-span="full" className={formStyles.group}>
        <legend>{t('categorizationRules.fields.axes')}</legend>
        <div className={styles.axes}>
          {AXES.map((axis) => (
            <label className={formStyles.choice} key={axis}>
              <input
                checked={targetAxes.includes(axis)}
                onChange={() => onToggleAxis(axis)}
                type="checkbox"
              />
              <span>{t(`categories.axes.${axis}`)}</span>
            </label>
          ))}
        </div>
      </fieldset>
    </FormSection>
  );
}
