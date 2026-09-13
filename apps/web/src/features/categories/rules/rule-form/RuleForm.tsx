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
import type { CategorizationRuleErrorKind } from '@/features/categories/rules/categorizationRuleError';
import { RuleAdvancedFields } from './advanced-fields/RuleAdvancedFields';
import { RuleConditions } from './conditionFields/RuleConditions';
import { validateRuleForm } from './ruleFormValidation';
import styles from './RuleForm.module.css';

type RulePayload = CreateCategorizationRuleRequest | UpdateCategorizationRuleRequest;
type Axis = AnalyticAxes[number];

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
  const periodError = showErrors && periodInvalid;

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
      <div className={styles.fieldGrid}>
        <label className={styles.field}>
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
            <small className={styles.error}>{t('categorizationRules.validation.label')}</small>
          ) : null}
        </label>
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
        <label className={styles.field}>
          <span>{t('categorizationRules.fields.effectiveFrom')}</span>
          <input
            aria-invalid={periodError ? true : undefined}
            onChange={(event) => setEffectiveFrom(event.target.value)}
            required
            type="date"
            value={effectiveFrom}
          />
          <small className={styles.hint}>{t('categorizationRules.fields.effectiveFromHint')}</small>
        </label>
        <label className={styles.field}>
          <span>{t('categorizationRules.fields.effectiveTo')}</span>
          <input
            aria-invalid={periodError ? true : undefined}
            onChange={(event) => setEffectiveTo(event.target.value)}
            type="date"
            value={effectiveTo}
          />
          <small className={periodError ? styles.error : styles.hint}>
            {t(
              periodError
                ? 'categorizationRules.validation.period'
                : 'categorizationRules.fields.effectiveToHint',
            )}
          </small>
        </label>
      </div>

      <RuleConditions
        amountInvalid={amountInvalid}
        conditions={conditions}
        conditionsInvalid={conditionsInvalid}
        onChange={setConditions}
        showErrors={showErrors}
        textInvalid={textInvalid}
      />

      <RuleAdvancedFields
        accountScope={accountScope}
        accounts={accounts}
        onClearAccounts={() => setAccountScope([])}
        onCounterpartyChange={setTargetCounterparty}
        onPriorityChange={setPriority}
        onToggleAccount={toggleAccount}
        onToggleAxis={toggleAxis}
        priority={priority}
        priorityInvalid={showErrors && priorityInvalid}
        targetAxes={targetAxes}
        targetCounterparty={targetCounterparty}
      />

      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'categorizationRules.form.saving' : 'categorizationRules.form.save')}
        </button>
      </div>
    </form>
  );
}
