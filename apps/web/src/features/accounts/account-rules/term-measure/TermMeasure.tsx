import { useTranslation } from 'react-i18next';
import { ruleTextKey } from '@/features/catalog-rules/ruleText';
import { humanToken } from '@/features/accounts/account-rules/term-wording/humanToken';

/**
 * A term states neither a ceiling nor a rate, so there is no figure it is
 * checked against. The cell says so rather than staying empty, which would
 * read as a measure nobody filled in. A wording this locale has not learned is
 * given here, in words, so the value line can stay short.
 */
export function TermMeasure({ token }: { token: string }) {
  const { t } = useTranslation();

  return (
    <>
      <span>{t('accounts.rules.noMeasure')}</span>
      {ruleTextKey(token) === null ? (
        <small>{t('accounts.rules.sourceWording', { wording: humanToken(token) })}</small>
      ) : null}
    </>
  );
}
