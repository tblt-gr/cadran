import { AuthenticatedApp } from '@/AuthenticatedApp';
import { AuthGate } from '@/features/auth/AuthGate';

function App() {
  return (
    <AuthGate>
      <AuthenticatedApp />
    </AuthGate>
  );
}

export default App;
