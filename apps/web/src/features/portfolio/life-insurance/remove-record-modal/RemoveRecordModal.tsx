import { ModalActions } from '@/components/ui/form-layout/ModalActions';
import { removeLifeInsuranceFeeTerms, removeEuroFundRate } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import {
  useLifeInsuranceMutation,
  lifeInsuranceError,
} from '@/features/portfolio/life-insurance/useLifeInsuranceMutation';
import styles from '@/features/portfolio/life-insurance/contract-form/ContractForm.module.css';
export function RemoveRecordModal({
  accountId,
  id,
  kind,
  onClose,
}: {
  accountId: string;
  id: string;
  kind: 'fee' | 'rate';
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const remove = useLifeInsuranceMutation(
    accountId,
    () =>
      (kind === 'fee' ? removeLifeInsuranceFeeTerms : removeEuroFundRate)({
        ...authApiOptions(),
        path: { id },
      }),
    onClose,
  );
  return (
    <Modal
      intent="delete"
      title={t(`portfolio.lifeInsurance.remove.title.${kind}`)}
      close={onClose}
    >
      <div className={styles.form}>
        <p>{t(`portfolio.lifeInsurance.remove.confirm.${kind}`)}</p>
        {remove.error ? (
          <p className={styles.error} role="alert">
            {lifeInsuranceError(remove.error)}
          </p>
        ) : null}
        <ModalActions>
          <button className="secondary-action" type="button" onClick={onClose}>
            {t('portfolio.lifeInsurance.remove.keep')}
          </button>
          <button
            className="primary-action"
            type="button"
            disabled={remove.isPending}
            onClick={() => remove.mutate(undefined)}
          >
            {remove.isPending
              ? t('portfolio.lifeInsurance.remove.removing')
              : t('portfolio.lifeInsurance.remove.action')}
          </button>
        </ModalActions>
      </div>
    </Modal>
  );
}
