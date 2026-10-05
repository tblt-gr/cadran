import { recordEuroFundRate, type LifeInsuranceEuroFundRateInput } from '@cadran/api-client';
import { Modal } from '@/components/ui/modal/Modal';
import { authApiOptions } from '@/features/auth/apiOptions';
import { EuroFundRateForm } from '@/features/portfolio/life-insurance/euro-fund-rate-form/EuroFundRateForm';
import {
  useLifeInsuranceMutation,
  lifeInsuranceError,
} from '@/features/portfolio/life-insurance/useLifeInsuranceMutation';
export function EuroFundRateModal({
  accountId,
  assetCode,
  subscribedOn,
  today,
  onClose,
}: {
  accountId: string;
  assetCode: string;
  subscribedOn: string;
  today: string;
  onClose: () => void;
}) {
  const save = useLifeInsuranceMutation(
    accountId,
    (body: LifeInsuranceEuroFundRateInput) =>
      recordEuroFundRate({ ...authApiOptions(), path: { accountId }, body }),
    onClose,
    [['instruments'], ['instrument-picker'], ['euro-fund-picker']],
  );
  return (
    <Modal title="Renseigner un taux" close={onClose}>
      <EuroFundRateForm
        assetCode={assetCode}
        subscribedOn={subscribedOn}
        today={today}
        pending={save.isPending}
        error={lifeInsuranceError(save.error)}
        onSubmit={(body) => save.mutate(body)}
      />
    </Modal>
  );
}
