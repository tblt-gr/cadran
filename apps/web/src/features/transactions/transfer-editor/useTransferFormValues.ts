import type { Account } from '@cadran/api-client';
import { useState } from 'react';
import { initialTransferFormValues, type TransferFormDefaults } from './initialTransferFormValues';
import type { TransferFormValues } from './transferFormValues';

/** Keep a locked transfer's default in sync with accounts arriving after the modal opens. */
export function useTransferFormValues(
  accounts: Account[],
  today: string,
  defaults?: TransferFormDefaults,
) {
  const [stored, setStored] = useState(() => initialTransferFormValues(accounts, today, defaults));
  const [destinationEdited, setDestinationEdited] = useState(false);
  const values =
    defaults?.lockedSourceAccountId && !destinationEdited
      ? {
          ...stored,
          targetAccountId: initialTransferFormValues(accounts, today, defaults).targetAccountId,
        }
      : stored;

  function set<K extends keyof TransferFormValues>(field: K, value: TransferFormValues[K]) {
    if (field === 'targetAccountId') setDestinationEdited(true);
    setStored((current) => ({ ...current, [field]: value }));
  }

  return { values, set };
}
