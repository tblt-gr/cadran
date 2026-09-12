import type {
  Account,
  CategorizationRule,
  CreateCategorizationRuleRequest,
  UpdateCategorizationRuleRequest,
} from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import type { CategorizationRuleErrorKind } from '../categorizationRuleError';
import { RuleForm } from '../rule-form/RuleForm';

interface RuleEditorProps {
  accounts: Account[];
  close: () => void;
  onSubmit: (body: CreateCategorizationRuleRequest | UpdateCategorizationRuleRequest) => void;
  pending: boolean;
  rule?: CategorizationRule;
  submitError: CategorizationRuleErrorKind | null;
}

export function RuleEditor({
  accounts,
  close,
  onSubmit,
  pending,
  rule,
  submitError,
}: RuleEditorProps) {
  const { t } = useTranslation();
  const creating = rule === undefined;
  return (
    <Modal
      close={close}
      eyebrow={t(
        creating
          ? 'categorizationRules.form.createEyebrow'
          : 'categorizationRules.form.editEyebrow',
      )}
      title={t(
        creating ? 'categorizationRules.form.createTitle' : 'categorizationRules.form.editTitle',
      )}
    >
      <RuleForm
        accounts={accounts}
        onCancel={close}
        onSubmit={onSubmit}
        pending={pending}
        rule={rule}
        submitError={submitError}
      />
    </Modal>
  );
}
