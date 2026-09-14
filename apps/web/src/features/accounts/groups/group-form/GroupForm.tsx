import type {
  AccountGroup,
  CreateAccountGroupRequest,
  UpdateAccountGroupRequest,
} from '@cadran/api-client';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import type { GroupErrorKind } from '@/features/accounts/groups/groupError';
import { ParentGroupField } from '@/features/accounts/groups/parent-group-field/ParentGroupField';
import styles from './GroupForm.module.css';

interface GroupFormProps {
  group?: AccountGroup;
  pending: boolean;
  submitError: GroupErrorKind | null;
  onCancel: () => void;
  onSubmit: (body: CreateAccountGroupRequest | UpdateAccountGroupRequest) => void;
}

export function GroupForm({ group, pending, submitError, onSubmit }: GroupFormProps) {
  const { t } = useTranslation();
  const [label, setLabel] = useState(group?.label ?? '');
  const [parentId, setParentId] = useState(group?.parentId ?? '');
  const [sortOrder, setSortOrder] = useState(String(group?.sortOrder ?? 0));
  const [showErrors, setShowErrors] = useState(false);

  const cleanLabel = label.trim();
  const parsedOrder = Number.parseInt(sortOrder, 10);
  const labelInvalid = cleanLabel.length < 1 || [...cleanLabel].length > 80;
  const orderInvalid =
    sortOrder.trim() === '' ||
    !/^[0-9]{1,5}$/.test(sortOrder.trim()) ||
    Number.isNaN(parsedOrder) ||
    parsedOrder < 0 ||
    parsedOrder > 32767;

  function submit(event: React.FormEvent) {
    event.preventDefault();
    if (labelInvalid || orderInvalid) {
      setShowErrors(true);
      return;
    }

    const common = {
      label: cleanLabel,
      parentId: parentId === '' ? null : parentId,
      sortOrder: parsedOrder,
    };

    onSubmit(group ? { ...common, version: group.version } : common);
  }

  return (
    <form className={styles.form} noValidate onSubmit={submit}>
      {submitError ? (
        <p className={styles.alert} role="alert">
          {t(`accountGroups.errors.${submitError}`)}
        </p>
      ) : null}

      <div className={styles.fields}>
        <label htmlFor="account-group-label">
          <span>{t('accountGroups.fields.label')}</span>
          <input
            aria-invalid={showErrors && labelInvalid}
            id="account-group-label"
            maxLength={80}
            onChange={(event) => setLabel(event.target.value)}
            value={label}
          />
          {showErrors && labelInvalid ? <small>{t('accountGroups.validation.label')}</small> : null}
        </label>

        <ParentGroupField currentId={group?.id} onChange={setParentId} value={parentId} />

        <label htmlFor="account-group-order">
          <span>{t('accountGroups.fields.order')}</span>
          <input
            aria-invalid={showErrors && orderInvalid}
            id="account-group-order"
            inputMode="numeric"
            onChange={(event) => setSortOrder(event.target.value)}
            value={sortOrder}
          />
          {showErrors && orderInvalid ? <small>{t('accountGroups.validation.order')}</small> : null}
        </label>
      </div>

      <div className={styles.actions}>
        <button className="primary-action" disabled={pending} type="submit">
          {t(pending ? 'accountGroups.form.saving' : 'accountGroups.form.save')}
        </button>
      </div>
    </form>
  );
}
