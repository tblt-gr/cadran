import { useTranslation } from 'react-i18next';
import { Modal } from '@/components/ui/modal/Modal';
import { metricPolicyErrorKind } from '@/features/metric-policy/metricPolicyError';
import { useCreateMetricPolicy } from '@/features/metric-policy/useMetricPolicies';
import { CreateMetricPolicyForm } from './CreateMetricPolicyForm';

interface CreateMetricPolicyModalProps {
  close: () => void;
}

export function CreateMetricPolicyModal({ close }: CreateMetricPolicyModalProps) {
  const { t } = useTranslation();
  const create = useCreateMetricPolicy(close);

  return (
    <Modal close={close} intent="create" title={t('metricPolicy.create.title')}>
      <CreateMetricPolicyForm
        onSubmit={(body) => create.mutate(body)}
        pending={create.isPending}
        submitError={metricPolicyErrorKind(create.error)}
      />
    </Modal>
  );
}
