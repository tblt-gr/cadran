import type { TransactionNature, TransactionSource, TransactionState } from '@cadran/api-client';
import type { TransactionAxis } from '@/features/transactions/transaction-filters/filterState';

export const STATES: TransactionState[] = ['PENDING', 'BOOKED', 'VOIDED', 'REJECTED'];
export const NATURES: TransactionNature[] = [
  'INCOME',
  'EXPENSE',
  'TRANSFER',
  'REFUND',
  'FEE',
  'ADJUSTMENT',
];
export const AXES: TransactionAxis[] = [
  'DISCRETIONARY',
  'ESSENTIAL',
  'FIXED',
  'PERSONAL',
  'PROFESSIONAL',
  'VARIABLE',
];
export const SOURCES: TransactionSource[] = ['MANUAL', 'IMPORT', 'PROVIDER'];
