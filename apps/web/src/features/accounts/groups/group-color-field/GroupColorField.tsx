import type { AccountGroup } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { canonicalGroupColor, NEW_GROUP_COLOR } from '@/features/accounts/group-color';
import styles from './GroupColorField.module.css';

type ColorMode = AccountGroup['colorMode'];

export interface GroupColorChange {
  mode: ColorMode;
  color: string | null;
}

interface GroupColorFieldProps {
  mode: ColorMode;
  color: string | null;
  /** Colors the non custom modes produce; null when they are not known before saving. */
  swatches: { AUTO: string | null; PARENT: string | null };
  /** A root has no parent: it is offered neither the shade nor the parent color. */
  root: boolean;
  /** Color a switch to custom mode starts from. */
  customStart?: string;
  disabled: boolean;
  invalid?: boolean;
  onChange: (change: GroupColorChange) => void;
}

export function GroupColorField({
  mode,
  color,
  swatches,
  root,
  customStart,
  disabled,
  invalid,
  onChange,
}: GroupColorFieldProps) {
  const { t } = useTranslation();
  const defaultMode: ColorMode = 'AUTO';
  const customColor = canonicalGroupColor(color) ?? customStart ?? NEW_GROUP_COLOR;
  // A sub-group whose shade is not known yet previews the parent color until it is saved.
  const inheritedColor =
    canonicalGroupColor(swatches[mode === 'PARENT' ? 'PARENT' : 'AUTO']) ??
    canonicalGroupColor(swatches.PARENT);
  const inputColor = mode === 'CUSTOM' ? customColor : (inheritedColor ?? customColor);
  const status =
    mode === 'CUSTOM'
      ? null
      : mode === 'PARENT'
        ? t('accountGroups.color.modes.PARENT')
        : root
          ? t('accountGroups.color.modes.AUTO_ROOT')
          : swatches.AUTO
            ? t('accountGroups.color.modes.AUTO_CHILD')
            : t('accountGroups.color.pendingShade');

  return (
    <fieldset
      aria-describedby={invalid ? 'account-group-color-error' : undefined}
      className={styles.field}
      disabled={disabled}
    >
      <legend>{t('accountGroups.color.legend')}</legend>
      <div className={styles.controls}>
        <input
          aria-invalid={invalid || undefined}
          aria-describedby={invalid ? 'account-group-color-error' : undefined}
          aria-label={t('accountGroups.fields.color')}
          id="account-group-color"
          onChange={(event) =>
            onChange({ mode: 'CUSTOM', color: canonicalGroupColor(event.target.value) })
          }
          type="color"
          value={inputColor}
        />
        {status ? <span className={styles.status}>{status}</span> : null}
        {mode !== defaultMode ? (
          <button
            className={styles.reset}
            onClick={() => onChange({ mode: defaultMode, color: null })}
            type="button"
          >
            {t('accountGroups.form.resetColor')}
          </button>
        ) : null}
      </div>
      {!root && mode !== 'PARENT' ? (
        <button
          className={styles.reset}
          onClick={() => onChange({ mode: 'PARENT', color: null })}
          type="button"
        >
          {t('accountGroups.form.useParentColor')}
        </button>
      ) : null}
      {invalid ? (
        <small id="account-group-color-error">{t('accountGroups.errors.color')}</small>
      ) : null}
      <p>{t('accountGroups.form.colorHelp')}</p>
    </fieldset>
  );
}
