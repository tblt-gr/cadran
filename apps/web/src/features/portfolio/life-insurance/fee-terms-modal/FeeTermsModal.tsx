import { addLifeInsuranceFeeTerms, type LifeInsuranceFeeTermsInput } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { FeeTermsForm } from '@/features/portfolio/life-insurance/fee-terms-form/FeeTermsForm';
import {
  useLifeInsuranceMutation,
  lifeInsuranceError,
} from '@/features/portfolio/life-insurance/useLifeInsuranceMutation';
export function FeeTermsModal({
  accountId,
  assetCode,
  subscribedOn,
  onClose,
}: {
  accountId: string;
  assetCode: string;
  subscribedOn: string;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const save = useLifeInsuranceMutation(
    accountId,
    (body: LifeInsuranceFeeTermsInput) =>
      addLifeInsuranceFeeTerms({ ...authApiOptions(), path: { accountId }, body }),
    onClose,
  );
  return (
    <Modal intent="create" title={t('portfolio.lifeInsurance.fees.add')} close={onClose}>
      <FeeTermsForm
        assetCode={assetCode}
        subscribedOn={subscribedOn}
        pending={save.isPending}
        error={lifeInsuranceError(save.error)}
        onSubmit={(body) => save.mutate(body)}
      />
    </Modal>
  );
}
