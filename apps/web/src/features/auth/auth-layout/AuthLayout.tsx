import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import styles from './AuthLayout.module.css';

interface AuthLayoutProps {
  children: ReactNode;
  title?: string;
  subtitle?: string;
  headingId?: string;
}

/**
 * Full-window frame for every pre-authentication screen: brand mark plus a
 * single centred card. No application shell is shown until a session exists.
 */
export function AuthLayout({ children, title, subtitle, headingId }: AuthLayoutProps) {
  const { t } = useTranslation();

  return (
    <main className={styles.screen}>
      <p className={styles.brand}>{t('app.name')}</p>
      <section className={`card ${styles.panel}`} aria-labelledby={title ? headingId : undefined}>
        {title ? (
          <div className={styles.heading}>
            <h1 id={headingId}>{title}</h1>
            {subtitle ? <p>{subtitle}</p> : null}
          </div>
        ) : null}
        {children}
      </section>
    </main>
  );
}
