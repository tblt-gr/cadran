import { useTranslation } from 'react-i18next';
import { canonicalGroupColor, NEW_GROUP_COLOR } from '@/features/accounts/group-color';
import styles from './GroupColorField.module.css';

interface GroupColorFieldProps {
  value: string | null;
  defaultColor?: string;
  disabled: boolean;
  invalid?: boolean;
  onChange: (color: string | null) => void;
}

export function GroupColorField({
  value,
  defaultColor,
  disabled,
  invalid,
  onChange,
}: GroupColorFieldProps) {
  const { t } = useTranslation();
  return (
    <div className={styles.field}>
      <label htmlFor="account-group-color">{t('accountGroups.fields.color')}</label>
      <div className={styles.controls}>
        <input
          aria-invalid={invalid || undefined}
          aria-describedby={invalid ? 'account-group-color-error' : undefined}
          disabled={disabled}
          id="account-group-color"
          onChange={(event) => onChange(canonicalGroupColor(event.target.value))}
          type="color"
          value={canonicalGroupColor(value) ?? canonicalGroupColor(defaultColor) ?? NEW_GROUP_COLOR}
        />
        <button disabled={disabled} onClick={() => onChange(null)} type="button">
          {t('accountGroups.form.resetColor')}
        </button>
      </div>
      {invalid ? (
        <small id="account-group-color-error">{t('accountGroups.errors.color')}</small>
      ) : null}
      <p>{t('accountGroups.form.colorHelp')}</p>
    </div>
  );
}
