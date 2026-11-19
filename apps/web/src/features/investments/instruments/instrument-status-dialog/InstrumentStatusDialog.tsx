import { ModalForm } from '@/components/ui/form-layout/ModalForm';
import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import type { Instrument } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import styles from './InstrumentStatusDialog.module.css';
export function InstrumentStatusDialog({
  error,
  instrument,
  pending,
  onCancel,
  onConfirm,
}: {
  error?: boolean;
  instrument: Instrument;
  pending: boolean;
  onCancel?: () => void;
  onConfirm: () => void;
}) {
  const { t } = useTranslation();
  const archive = instrument.status === 'ACTIVE';
  return (
    <ModalForm
      className={styles.dialog}
      onSubmit={(event) => {
        event.preventDefault();
        onConfirm();
      }}
    >
      <p>
        {archive
          ? t('investments.instruments.statusDialog.archiveText')
          : t('investments.instruments.statusDialog.reactivateText')}
      </p>
      {error ? <p role="alert">{t('investments.instruments.statusDialog.error')}</p> : null}
      <ModalActions>
        {onCancel ? (
          <button className="secondary-action" type="button" onClick={onCancel}>
            {t('actions.cancel')}
          </button>
        ) : null}
        <button className="primary-action" disabled={pending} type="submit">
          {pending
            ? t('investments.instruments.form.saving')
            : error
              ? t('actions.retry')
              : archive
                ? t('investments.instruments.actions.archive')
                : t('investments.instruments.actions.reactivate')}
        </button>
      </ModalActions>
    </ModalForm>
  );
}
