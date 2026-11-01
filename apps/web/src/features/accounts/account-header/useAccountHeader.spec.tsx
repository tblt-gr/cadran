import type { Account } from '@cadran/api-client';
import { cleanup, renderHook } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import {
  HeaderTitleProvider,
  useHeaderSubtitleValue,
  useHeaderTitleValue,
} from '@/components/layout/header/HeaderTitleContext';
import { useAccountHeader } from './useAccountHeader';

const account = { label: 'Livret', institution: 'Banque X', productCode: 'P' } as Account;

afterEach(cleanup);

describe('useAccountHeader', () => {
  it('publishes the account name without a subtitle: institution and product are in the panel', () => {
    const wrapper = ({ children }: { children: ReactNode }) => (
      <HeaderTitleProvider>{children}</HeaderTitleProvider>
    );
    const { result } = renderHook(
      () => {
        useAccountHeader('/x', account);
        return [useHeaderTitleValue('/x'), useHeaderSubtitleValue('/x')] as const;
      },
      { wrapper },
    );

    expect(result.current).toEqual(['Livret', undefined]);
  });
});
