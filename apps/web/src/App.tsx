import { getFoundationStatus } from '@cadran/api-client';
import { useQuery } from '@tanstack/react-query';
import './App.css';

function App() {
  const status = useQuery({
    queryKey: ['foundation-status'],
    queryFn: async ({ signal }) => {
      const response = await getFoundationStatus({
        baseUrl: window.location.origin,
        signal,
        throwOnError: true,
      });

      return response.data;
    },
    retry: false,
  });

  if (status.isPending) {
    return (
      <main className="foundation-state" aria-labelledby="foundation-title">
        <h1 id="foundation-title">Cadran Budget</h1>
        <p role="status">Connecting to the API…</p>
      </main>
    );
  }

  if (status.isError) {
    return (
      <main className="foundation-state" aria-labelledby="foundation-title">
        <div role="alert">
          <h1 id="foundation-title">Cadran Budget</h1>
          <p>The API is currently unavailable.</p>
        </div>
        <button
          type="button"
          onClick={() => {
            void status.refetch();
          }}
        >
          Try again
        </button>
      </main>
    );
  }

  return (
    <main className="foundation-state" aria-labelledby="foundation-title">
      <p className="eyebrow">API {status.data.apiVersion}</p>
      <h1 id="foundation-title">Cadran Budget</h1>
      <p role="status">The application foundation is ready.</p>
    </main>
  );
}

export default App;
