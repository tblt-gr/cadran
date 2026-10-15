import { useLayoutEffect, type ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { AuthLayout } from '@/features/auth/auth-layout/AuthLayout';
import { loginPath } from '@/features/auth/authPath';
import { CreatePasswordForm } from '@/features/auth/create-password-form/CreatePasswordForm';
import { LoginForm } from '@/features/auth/login-form/LoginForm';
import { SessionProbeSkeleton } from '@/features/auth/session-probe-skeleton/SessionProbeSkeleton';
import { useSession } from '@/features/auth/useSession';
import styles from './AuthGate.module.css';

/**
 * The authentication boundary. No authenticated page or query runs until the
 * server reports an active session. A login URL shows the disabled login form
 * during the probe; private URLs keep their inert page skeleton. First-run
 * installs are routed to password setup.
 */
export function AuthGate({ children }: { children: ReactNode }) {
  const { t } = useTranslation();
  const session = useSession();
  const refetch = () => void session.refetch();

  useLayoutEffect(() => {
    if (session.data?.authenticated && window.location.pathname === loginPath) {
      window.history.replaceState(null, '', '/');
    }
  }, [session.data]);

  if (session.isPending) {
    if (window.location.pathname === loginPath) {
      return (
        <AuthLayout
          title={t('auth.login.title')}
          subtitle={t('auth.login.description')}
          headingId="auth-login-title"
        >
          <LoginForm onSuccess={refetch} disabled />
        </AuthLayout>
      );
    }

    return <SessionProbeSkeleton />;
  }

  if (session.isError) {
    return (
      <AuthLayout
        title={t('auth.error.title')}
        subtitle={t('auth.error.description')}
        headingId="auth-error-title"
      >
        <div role="alert">
          <button className="secondary-action" type="button" onClick={refetch}>
            {t('auth.error.retry')}
          </button>
        </div>
      </AuthLayout>
    );
  }

  if (!session.data.provisioned) {
    return (
      <AuthLayout
        title={t('auth.notProvisioned.title')}
        subtitle={t('auth.notProvisioned.description')}
        headingId="auth-not-provisioned-title"
      >
        <p className={styles.command}>
          <code>{t('auth.notProvisioned.command')}</code>
        </p>
      </AuthLayout>
    );
  }

  if (session.data.setupRequired) {
    return (
      <AuthLayout
        title={t('auth.createPassword.title')}
        subtitle={t('auth.createPassword.description')}
        headingId="auth-setup-title"
      >
        <CreatePasswordForm onDone={refetch} />
      </AuthLayout>
    );
  }

  if (!session.data.authenticated) {
    return (
      <AuthLayout
        title={t('auth.login.title')}
        subtitle={t('auth.login.description')}
        headingId="auth-login-title"
      >
        <LoginForm onSuccess={refetch} />
      </AuthLayout>
    );
  }

  return <>{children}</>;
}
