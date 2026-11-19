import { useTranslation } from 'react-i18next';
import { BusinessDateField } from '@/components/ui/business-date-field/BusinessDateField';
import { lastTwelveMonths, startOfYear } from './cashPeriod';
import styles from './CashTab.module.css';

interface Props {
  disabled?: boolean;
  from: string;
  onChange: (range: { from: string; to: string }) => void;
  to: string;
  today: string;
}

/** Period selector shared by the live cash tab and its loading preview. */
export function CashToolbar({ disabled = false, from, onChange, to, today }: Props) {
  const { t } = useTranslation();
  return (
    <fieldset className={styles.toolbar} disabled={disabled}>
      <legend className="sr-only">{t('portfolio.cash.toolbar.legend')}</legend>
      <BusinessDateField
        hint={null}
        label={t('portfolio.cash.toolbar.from')}
        onChange={(value) => onChange({ from: value, to })}
        resetLabel={t('portfolio.cash.toolbar.yearStart')}
        today={startOfYear(today)}
        value={from}
      />
      <BusinessDateField
        hint={null}
        label={t('portfolio.cash.toolbar.to')}
        onChange={(value) => onChange({ from, to: value > today ? today : value })}
        today={today}
        value={to}
      />
      <div className={styles.shortcuts}>
        <button
          className="secondary-action"
          onClick={() => onChange({ from: startOfYear(today), to: today })}
          type="button"
        >
          {t('portfolio.cash.toolbar.thisYear')}
        </button>
        <button
          className="secondary-action"
          onClick={() => onChange({ from: lastTwelveMonths(today), to: today })}
          type="button"
        >
          {t('portfolio.cash.toolbar.lastTwelveMonths')}
        </button>
      </div>
    </fieldset>
  );
}
