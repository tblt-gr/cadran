import { removeLifeInsuranceFeeTerms, removeEuroFundRate } from '@cadran/api-client';
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
    <Modal title={kind === 'fee' ? 'Supprimer le barème' : 'Supprimer le taux'} close={onClose}>
      <div className={styles.form}>
        <p>
          Confirmez la suppression de {kind === 'fee' ? 'ce barème de frais' : 'ce taux servi'}.
          Vous pourrez le renseigner à nouveau.
        </p>
        {remove.error ? (
          <p className={styles.error} role="alert">
            {lifeInsuranceError(remove.error)}
          </p>
        ) : null}
        <div className={styles.actions}>
          <button className="secondary-action" type="button" onClick={onClose}>
            Conserver
          </button>
          <button
            className="primary-action"
            type="button"
            disabled={remove.isPending}
            onClick={() => remove.mutate(undefined)}
          >
            {remove.isPending ? 'Suppression…' : 'Supprimer'}
          </button>
        </div>
      </div>
    </Modal>
  );
}
