import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { FormGrid } from '@/components/ui/form-layout/FormGrid';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { useState, type FormEvent } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './VoidOperationDialog.module.css';

export function VoidOperationDialog({
  error,
  type,
  onCancel,
  onConfirm,
  pending,
}: {
  error?: string;
  type?: string;
  onCancel?: () => void;
  onConfirm: (reason: string) => void;
  pending: boolean;
}) {
  const { t } = useTranslation();
  const [reason, setReason] = useState('');
  const [missing, setMissing] = useState(false);

  function submit(event: FormEvent) {
    event.preventDefault();
    const trimmed = reason.trim();
    if (trimmed === '') {
      setMissing(true);
      return;
    }
    onConfirm(trimmed);
  }

  return (
    <ModalForm className={styles.dialog} noValidate onSubmit={submit}>
      {type === 'SWITCH' ? <p>{t('portfolio.account.void.wholeSwitch')}</p> : null}
      <p>{t('portfolio.account.void.notice')}</p>
      <FormGrid>
        <label data-span="full">
          <span>{t('portfolio.account.detail.reason')}</span>
          <textarea
            data-autofocus
            maxLength={200}
            onChange={(event) => setReason(event.target.value)}
            rows={3}
            value={reason}
          />
          {missing ? (
            <small className={styles.error} role="alert">
              {t('portfolio.account.void.reasonRequired')}
            </small>
          ) : null}
        </label>
      </FormGrid>
      {error ? (
        <p className={styles.error} role="alert">
          {error}
        </p>
      ) : null}
      <ModalActions>
        {onCancel ? (
          <button className="secondary-action" type="button" onClick={onCancel}>
            {t('actions.cancel')}
          </button>
        ) : null}
        <button className="primary-action" disabled={pending} type="submit">
          {pending ? t('portfolio.account.void.pending') : t('portfolio.account.void.confirm')}
        </button>
      </ModalActions>
    </ModalForm>
  );
}
