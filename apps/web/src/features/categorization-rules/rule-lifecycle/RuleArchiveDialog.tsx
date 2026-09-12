import type { CategorizationRule } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import type { CategorizationRuleErrorKind } from '../categorizationRuleError';
import styles from './RuleArchiveDialog.module.css';

interface RuleArchiveDialogProps {
  onCancel: () => void;
  onConfirm: () => void;
  pending: boolean;
  rule: CategorizationRule;
  submitError: CategorizationRuleErrorKind | null;
}

export function RuleArchiveDialog({
  onCancel: _onCancel,
  onConfirm,
  pending,
  rule,
  submitError,
}: RuleArchiveDialogProps) {
  const { t } = useTranslation();
  return (
    <div className={styles.dialog}>
      <p>{t('categorizationRules.archive.description', { label: rule.label })}</p>
      <p>{t('categorizationRules.archive.consequences')}</p>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`categorizationRules.errors.${submitError}`)}
        </p>
      ) : null}
      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} onClick={onConfirm} type="button">
          {t(
            pending
              ? 'categorizationRules.archive.archiving'
              : 'categorizationRules.archive.confirm',
          )}
        </button>
      </div>
    </div>
  );
}
