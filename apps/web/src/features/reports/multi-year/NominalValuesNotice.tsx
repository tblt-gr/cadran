import { useTranslation } from 'react-i18next';

/** Reminds the reader that the multi-year figures are nominal: no inflation adjustment exists yet. */
export function NominalValuesNotice() {
  const { t } = useTranslation();

  return <p role="note">{t('reports.multiYear.nominalNotice')}</p>;
}
