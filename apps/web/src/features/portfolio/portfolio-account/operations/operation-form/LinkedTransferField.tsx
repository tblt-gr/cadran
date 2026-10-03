import {
  listPortfolioTransferCandidates,
  type PortfolioTransferCandidate,
} from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';
import { authApiOptions } from '@/features/auth/apiOptions';
import { formatAmount, formatCalendarNumericDay } from '@/lib/decimal';
import { OperationField } from './OperationField';

/** The transfer legs a deposit or withdrawal can mirror; choosing one fills date and amount. */
export function LinkedTransferField({
  accountId,
  direction,
  error,
  onChange,
  value,
}: {
  accountId: string;
  direction: 'IN' | 'OUT';
  error?: string;
  onChange: (candidate: PortfolioTransferCandidate | null, id: string) => void;
  value: string;
}) {
  const { i18n } = useTranslation();
  const candidates = useQuery({
    queryKey: ['portfolio-transfer-candidates', accountId, direction],
    queryFn: async ({ signal }) => {
      const result = await listPortfolioTransferCandidates({
        ...authApiOptions(),
        path: { accountId },
        query: { direction },
        signal,
      });
      if (!result.response?.ok || !result.data) throw new Error('Transfer candidates failed');
      return result.data.items;
    },
    retry: false,
  });
  const items = candidates.data ?? [];

  return (
    <OperationField error={error} label="Virement lié">
      <select
        onChange={(event) =>
          onChange(
            items.find((item) => item.transactionId === event.target.value) ?? null,
            event.target.value,
          )
        }
        value={value}
      >
        <option value="">Aucun virement lié</option>
        {value !== '' && !items.some((item) => item.transactionId === value) ? (
          <option value={value}>Virement actuellement lié</option>
        ) : null}
        {items.map((item) => (
          <option key={item.transactionId} value={item.transactionId}>
            {formatCalendarNumericDay(item.bookedOn)} ·{' '}
            {formatAmount(item.amount.value, item.amount.assetCode, i18n.language)}
            {item.label ? ` · ${item.label}` : ''}
          </option>
        ))}
      </select>
      {candidates.isError ? <small role="alert">Les virements sont indisponibles.</small> : null}
    </OperationField>
  );
}
