import { AmountPrivacyProvider } from '@/components/ui/amount-privacy/AmountPrivacyProvider';
import { AuthenticatedApp } from '@/AuthenticatedApp';
import { AuthGate } from '@/features/auth/AuthGate';

function App() {
  return (
    <AmountPrivacyProvider>
      <AuthGate>
        <AuthenticatedApp />
      </AuthGate>
    </AmountPrivacyProvider>
  );
}

export default App;
