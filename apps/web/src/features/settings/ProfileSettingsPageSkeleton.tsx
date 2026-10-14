import { useTranslation } from 'react-i18next';
import { SkeletonBlock, SkeletonField, SkeletonStatus } from '@/components/ui/skeleton/Skeleton';
import passwordStyles from './change-password-form/ChangePasswordForm.module.css';
import { MIN_LENGTH } from './change-password-form/passwordLength';
import displayNameStyles from './display-name-form/DisplayNameForm.module.css';
import styles from './ProfileSettingsPage.module.css';

/** Titles, labels and buttons are static and real: only the account's own values are pending. */
export function ProfileSettingsPageSkeleton({ status }: { status: string }) {
  const { t } = useTranslation();
  return (
    <div aria-busy="true" className={styles.sections} data-testid="profile-settings-skeleton">
      <SkeletonStatus>{status}</SkeletonStatus>
      <section className={`card ${styles.section}`}>
        <header>
          <h3>{t('settings.profile.title')}</h3>
          <p>{t('settings.profile.description')}</p>
        </header>
        <dl className={styles.readOnly}>
          <dt>{t('settings.profile.fields.email')}</dt>
          <dd>
            <SkeletonBlock className={styles.skeletonValue} />
          </dd>
        </dl>
        <div className={displayNameStyles.form}>
          <SkeletonField label={t('settings.profile.fields.displayName')} />
          <button className={`primary-action ${displayNameStyles.submit}`} disabled type="button">
            {t('settings.profile.submit')}
          </button>
        </div>
      </section>
      <section className={`card ${styles.section}`}>
        <header>
          <h3>{t('settings.password.title')}</h3>
          <p>{t('settings.password.description')}</p>
        </header>
        <div className={passwordStyles.form}>
          <SkeletonField label={t('settings.password.fields.currentPassword')} />
          <SkeletonField label={t('settings.password.fields.newPassword')} />
          <p className={passwordStyles.hint}>{t('settings.password.hint', { min: MIN_LENGTH })}</p>
          <SkeletonField label={t('settings.password.fields.confirmPassword')} />
          <button className={`secondary-action ${passwordStyles.submit}`} disabled type="button">
            {t('settings.password.submit')}
          </button>
        </div>
      </section>
    </div>
  );
}
