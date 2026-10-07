import { OperationDecimalField } from '@/features/portfolio/portfolio-account/operations/operation-form/OperationDecimalField';
import { SupportLegsField } from '@/features/portfolio/portfolio-account/operations/operation-form/support-legs-field/SupportLegsField';
import type {
  OperationFieldErrors,
  OperationFormValues,
} from '@/features/portfolio/portfolio-account/operations/operation-form/operationRequest';

export function SwitchOperationFields({
  account,
  errors,
  values,
  onChange,
}: {
  account: { assetCode: string };
  errors: OperationFieldErrors;
  values: OperationFormValues;
  onChange: <K extends keyof OperationFormValues>(key: K, value: OperationFormValues[K]) => void;
}) {
  const outgoing = values.legs.filter((leg) => leg.direction !== 'IN');
  const incoming = values.legs.filter((leg) => leg.direction === 'IN');
  return (
    <>
      <SupportLegsField
        label="Supports vendus"
        maxLegs={10}
        assetCode={account.assetCode}
        errors={errors}
        value={outgoing}
        onChange={(legs) =>
          onChange('legs', [
            ...legs.map((leg) => ({ ...leg, direction: 'OUT' as const })),
            ...incoming,
          ])
        }
      />
      <SupportLegsField
        label="Supports achetés"
        maxLegs={10}
        indexOffset={outgoing.length}
        assetCode={account.assetCode}
        errors={errors}
        value={incoming}
        onChange={(legs) =>
          onChange('legs', [
            ...outgoing,
            ...legs.map((leg) => ({ ...leg, direction: 'IN' as const })),
          ])
        }
      />
      <OperationDecimalField
        label="Frais d’arbitrage"
        error={errors.fees}
        value={values.fees}
        onChange={(value) => onChange('fees', value)}
      />
    </>
  );
}
