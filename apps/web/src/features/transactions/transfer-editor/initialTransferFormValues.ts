import type { Account } from '@cadran/api-client';
import { initialTransferValues, type TransferFormValues } from './transferFormValues';

export interface TransferFormDefaults {
  bookedOn?: string;
  targetAccountId?: string;
  lockedSourceAccountId?: string;
}

export function initialTransferFormValues(
  accounts: Account[],
  today: string,
  defaults?: TransferFormDefaults,
): TransferFormValues {
  const initial = initialTransferValues(accounts, defaults?.bookedOn ?? today);
  const primary = accounts.find((account) => account.isPrimary && account.status === 'ACTIVE');
  if (defaults?.lockedSourceAccountId) {
    return {
      ...initial,
      sourceAccountId: defaults.lockedSourceAccountId,
      targetAccountId: primary && primary.id !== defaults.lockedSourceAccountId ? primary.id : '',
    };
  }
  if (defaults?.targetAccountId !== undefined) {
    return {
      ...initial,
      sourceAccountId: primary && primary.id !== defaults.targetAccountId ? primary.id : '',
      targetAccountId: defaults.targetAccountId,
    };
  }
  return initial;
}
