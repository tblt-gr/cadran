import { useTranslation } from 'react-i18next';
import { OperationDecimalField } from '@/features/portfolio/portfolio-account/operations/operation-form/OperationDecimalField';
import styles from './OperationRatioFields.module.css';

export function OperationRatioFields({
  denominator,
  error,
  numerator,
  onDenominatorChange,
  onNumeratorChange,
}: {
  denominator: string;
  error?: string;
  numerator: string;
  onDenominatorChange: (value: string) => void;
  onNumeratorChange: (value: string) => void;
}) {
  const { t } = useTranslation();
  return (
    <fieldset className={styles.ratio}>
      <legend>{t('portfolio.account.form.ratio.legend')}</legend>
      <OperationDecimalField
        label={t('portfolio.account.form.ratio.numerator')}
        onChange={onNumeratorChange}
        value={numerator}
      />
      <OperationDecimalField
        label={t('portfolio.account.form.ratio.denominator')}
        onChange={onDenominatorChange}
        value={denominator}
      />
      {error ? (
        <small className={styles.error} role="alert">
          {error}
        </small>
      ) : null}
    </fieldset>
  );
}
