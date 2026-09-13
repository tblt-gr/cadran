import type {
  Account,
  AnalyticAxes,
  CategorizationRule,
  CategorizationRuleConditions,
  CreateCategorizationRuleRequest,
  UpdateCategorizationRuleRequest,
} from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { CategoryPicker } from '@/features/categories/category-picker/CategoryPicker';
import type { CategorizationRuleErrorKind } from '@/features/categorization-rules/categorizationRuleError';
import { AmountConditionFields } from './conditionFields/AmountConditionFields';
import { OtherConditionFields } from './conditionFields/OtherConditionFields';
import { TextConditionFields } from './conditionFields/TextConditionFields';
import { validateRuleForm } from './ruleFormValidation';
import styles from './RuleForm.module.css';

type RulePayload = CreateCategorizationRuleRequest | UpdateCategorizationRuleRequest;
type Axis = AnalyticAxes[number];

const AXES: Axis[] = [
  'ESSENTIAL',
  'DISCRETIONARY',
  'FIXED',
  'VARIABLE',
  'PERSONAL',
  'PROFESSIONAL',
];

interface RuleFormProps {
  accounts: Account[];
  onSubmit: (body: RulePayload) => void;
  pending: boolean;
  rule?: CategorizationRule;
  submitError: CategorizationRuleErrorKind | null;
}

function emptyConditions(): CategorizationRuleConditions {
  return {
    amount: null,
    direction: null,
    mcc: null,
    text: null,
  };
}

export function RuleForm({ accounts, onSubmit, pending, rule, submitError }: RuleFormProps) {
  const { t } = useTranslation();
  const [label, setLabel] = useState(rule?.label ?? '');
  const [priority, setPriority] = useState(String(rule?.priority ?? 100));
  const [accountScope, setAccountScope] = useState<string[]>(rule?.accountScope ?? []);
  const [conditions, setConditions] = useState<CategorizationRuleConditions>(
    rule?.conditions ?? emptyConditions(),
  );
  const [targetCategoryId, setTargetCategoryId] = useState(rule?.targetCategoryId ?? '');
  const [targetCategoryLabel, setTargetCategoryLabel] = useState(rule?.targetCategoryLabel ?? null);
  const [targetAxes, setTargetAxes] = useState<Axis[]>(rule?.targetAxes ?? []);
  const [targetCounterparty, setTargetCounterparty] = useState(rule?.targetCounterparty ?? '');
  const [effectiveFrom, setEffectiveFrom] = useState(rule?.effectiveFrom ?? '');
  const [effectiveTo, setEffectiveTo] = useState(rule?.effectiveTo ?? '');
  const [showErrors, setShowErrors] = useState(false);

  const {
    amountInvalid,
    cleanLabel,
    conditionsInvalid,
    isValid,
    labelInvalid,
    parsedPriority,
    periodInvalid,
    priorityInvalid,
    targetInvalid,
    textInvalid,
  } = validateRuleForm({
    conditions,
    effectiveFrom,
    effectiveTo,
    label,
    priority,
    targetCategoryId,
  });

  function toggleAccount(id: string) {
    setAccountScope((current) =>
      current.includes(id) ? current.filter((value) => value !== id) : [...current, id],
    );
  }

  function toggleAxis(axis: Axis) {
    setTargetAxes((current) =>
      current.includes(axis) ? current.filter((value) => value !== axis) : [...current, axis],
    );
  }

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (!isValid) {
      setShowErrors(true);
      return;
    }
    const common = {
      accountScope,
      conditions,
      effectiveFrom,
      effectiveTo: effectiveTo === '' ? null : effectiveTo,
      label: cleanLabel,
      priority: parsedPriority,
      targetAxes,
      targetCategoryId,
      targetCounterparty: targetCounterparty.trim() === '' ? null : targetCounterparty.trim(),
    };
    onSubmit(rule ? { ...common, active: rule.active, version: rule.version } : common);
  }

  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`categorizationRules.errors.${submitError}`)}
        </p>
      ) : null}
      <div className={styles.fields}>
        <label>
          <span>{t('categorizationRules.fields.label')}</span>
          <input
            aria-invalid={showErrors && labelInvalid ? true : undefined}
            autoComplete="off"
            data-autofocus
            maxLength={80}
            onChange={(event) => setLabel(event.target.value)}
            required
            value={label}
          />
          {showErrors && labelInvalid ? (
            <small>{t('categorizationRules.validation.label')}</small>
          ) : null}
        </label>
        <label>
          <span>{t('categorizationRules.fields.priority')}</span>
          <input
            aria-invalid={showErrors && priorityInvalid ? true : undefined}
            inputMode="numeric"
            onChange={(event) => setPriority(event.target.value)}
            value={priority}
          />
          {showErrors && priorityInvalid ? (
            <small>{t('categorizationRules.validation.priority')}</small>
          ) : null}
        </label>
      </div>

      <fieldset className={styles.scope}>
        <legend>{t('categorizationRules.fields.accountScope')}</legend>
        <p>{t('categorizationRules.fields.accountScopeHint')}</p>
        {accounts.map((account) => (
          <label key={account.id}>
            <input
              checked={accountScope.includes(account.id)}
              onChange={() => toggleAccount(account.id)}
              type="checkbox"
            />
            <span>
              {account.label} · {account.assetCode}
            </span>
          </label>
        ))}
      </fieldset>

      <TextConditionFields
        conditions={conditions}
        invalid={textInvalid}
        onChange={setConditions}
        showErrors={showErrors}
      />
      <AmountConditionFields conditions={conditions} onChange={setConditions} />
      <OtherConditionFields conditions={conditions} onChange={setConditions} />
      {showErrors && (conditionsInvalid || amountInvalid) ? (
        <p className={styles.alert} role="alert">
          {t(
            conditionsInvalid
              ? 'categorizationRules.validation.conditions'
              : 'categorizationRules.validation.amount',
          )}
        </p>
      ) : null}

      <div className={styles.fields}>
        <CategoryPicker
          error={showErrors && targetInvalid ? t('categorizationRules.validation.category') : null}
          label={t('categorizationRules.fields.targetCategory')}
          onChange={(id, category) => {
            setTargetCategoryId(id);
            setTargetCategoryLabel(category?.label ?? null);
          }}
          selectedLabel={targetCategoryLabel}
          value={targetCategoryId}
        />
        <label>
          <span>{t('categorizationRules.fields.counterparty')}</span>
          <input
            maxLength={80}
            onChange={(event) => setTargetCounterparty(event.target.value)}
            value={targetCounterparty}
          />
        </label>
        <label>
          <span>{t('categorizationRules.fields.effectiveFrom')}</span>
          <input
            aria-invalid={showErrors && periodInvalid ? true : undefined}
            onChange={(event) => setEffectiveFrom(event.target.value)}
            type="date"
            value={effectiveFrom}
          />
        </label>
        <label>
          <span>{t('categorizationRules.fields.effectiveTo')}</span>
          <input
            aria-invalid={showErrors && periodInvalid ? true : undefined}
            onChange={(event) => setEffectiveTo(event.target.value)}
            type="date"
            value={effectiveTo}
          />
          {showErrors && periodInvalid ? (
            <small>{t('categorizationRules.validation.period')}</small>
          ) : null}
        </label>
      </div>

      <fieldset className={styles.scope}>
        <legend>{t('categorizationRules.fields.axes')}</legend>
        {AXES.map((axis) => (
          <label key={axis}>
            <input
              checked={targetAxes.includes(axis)}
              onChange={() => toggleAxis(axis)}
              type="checkbox"
            />
            <span>{t(`categories.axes.${axis}`)}</span>
          </label>
        ))}
      </fieldset>
      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'categorizationRules.form.saving' : 'categorizationRules.form.save')}
        </button>
      </div>
    </form>
  );
}
