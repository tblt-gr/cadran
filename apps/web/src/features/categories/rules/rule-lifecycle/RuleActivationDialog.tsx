import type { CategorizationRule } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import type { CategorizationRuleErrorKind } from '@/features/categories/rules/categorizationRuleError';
import styles from './RuleArchiveDialog.module.css';

interface RuleActivationDialogProps {
  onConfirm: () => void;
  pending: boolean;
  rule: CategorizationRule;
  submitError: CategorizationRuleErrorKind | null;
}

export function RuleActivationDialog({
  onConfirm,
  pending,
  rule,
  submitError,
}: RuleActivationDialogProps) {
  const { t } = useTranslation();
  const key = rule.active ? 'deactivate' : 'activate';
  return (
    <div className={styles.dialog}>
      <p>{t(`categorizationRules.lifecycle.${key}.description`, { label: rule.label })}</p>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`categorizationRules.errors.${submitError}`)}
        </p>
      ) : null}
      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} onClick={onConfirm} type="button">
          {t(
            pending
              ? 'categorizationRules.lifecycle.saving'
              : `categorizationRules.lifecycle.${key}.confirm`,
          )}
        </button>
      </div>
    </div>
  );
}
