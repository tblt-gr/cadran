import type { AnnualPolicy } from '@cadran/api-client';
import { useTranslation } from 'react-i18next';
import { handleClientNavigation } from '@/hooks/use-client-navigation';
import styles from './MixedPolicyBanner.module.css';

export function MixedPolicyBanner({ policy }: { policy: AnnualPolicy }) {
  const { t } = useTranslation();

  return (
    <aside className={styles.banner} role="status">
      <p>{t('reports.annual.mixedPolicy', { versions: policy.versions.join(', ') })}</p>
      <a
        href="/settings/metric-policy"
        onClick={(event) => handleClientNavigation(event, '/settings/metric-policy')}
      >
        {t('reports.annual.mixedPolicyLink')}
      </a>
    </aside>
  );
}
