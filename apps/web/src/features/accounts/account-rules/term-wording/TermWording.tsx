import { useTranslation } from 'react-i18next';
import { ruleTextKey } from '@/features/catalog-rules/ruleText';

interface TermWordingProps {
  token: string;
}

/**
 * The contractual wording behind a term's token.
 *
 * Regulatory wording belongs to the publication it was read from and to the
 * translation catalogue, never to the API payload, which is why the value
 * travels as a token. A token nothing translates is never shown as the raw
 * identifier: the line says a specific condition applies, and the details
 * quote it in words.
 */
export function TermWording({ token }: TermWordingProps) {
  const { t } = useTranslation();
  const wording = ruleTextKey(token);

  return <>{wording === null ? t('accounts.rules.termOther') : t(wording)}</>;
}
