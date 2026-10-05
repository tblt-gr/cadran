import { useState } from 'react';
import {
  saveLifeInsuranceContract,
  type LifeInsuranceContract,
  type LifeInsuranceContractInput,
} from '@cadran/api-client';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { ContractForm } from '@/features/portfolio/life-insurance/contract-form/ContractForm';
import {
  useLifeInsuranceMutation,
  lifeInsuranceError,
} from '@/features/portfolio/life-insurance/useLifeInsuranceMutation';
export function ContractModal({
  accountId,
  contract,
  today,
  onClose,
}: {
  accountId: string;
  contract?: LifeInsuranceContract;
  today: string;
  onClose: () => void;
}) {
  // A query refresh must not adopt a newer version for fields edited against the opening state.
  const [baseline] = useState(contract);
  const save = useLifeInsuranceMutation(
    accountId,
    (body: LifeInsuranceContractInput) =>
      saveLifeInsuranceContract({ ...authApiOptions(), path: { accountId }, body }),
    onClose,
  );
  return (
    <Modal title={baseline ? 'Modifier le contrat' : 'Renseigner le contrat'} close={onClose}>
      <ContractForm
        contract={baseline}
        today={today}
        pending={save.isPending}
        error={lifeInsuranceError(save.error)}
        onSubmit={(body) => save.mutate(body)}
      />
    </Modal>
  );
}
