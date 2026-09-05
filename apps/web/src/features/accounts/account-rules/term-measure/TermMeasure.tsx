import { useTranslation } from 'react-i18next';

/**
 * A term states neither a ceiling nor a rate, so there is no figure it is
 * checked against. The cell says so rather than staying empty, which would
 * read as a measure nobody filled in.
 */
export function TermMeasure() {
  const { t } = useTranslation();

  return <>{t('accounts.rules.noMeasure')}</>;
}
