import { signedPresentation } from '@/lib/decimal';

/** Direction comes from the exact decimal, with no direction in privacy mode. */
export function deltaDirection(value: string): 'down' | 'flat' | 'up' {
  const sign = signedPresentation('', value).sign;
  return sign < 0 ? 'down' : sign > 0 ? 'up' : 'flat';
}
