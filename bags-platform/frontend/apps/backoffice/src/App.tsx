import { lazy, Suspense } from 'react';
import { defaultApiClient, resolveServiceUrl } from '@alfarid/api-client';
import { AdminLoading } from './components/admin/AdminLoading';
import { ProtectedRoute } from './components/auth/ProtectedRoute';
import AuthCallbackPage from './pages/auth/AuthCallbackPage';
import LoginPage from './pages/auth/LoginPage';

const AuthenticatedApp = lazy(() => import('./AuthenticatedApp'));

defaultApiClient.defaults.baseURL = resolveServiceUrl(import.meta.env.VITE_API_URL ?? 'http://localhost:8000');

export function App() {
  if (window.location.pathname === '/auth/callback') return <AuthCallbackPage />;
  if (window.location.pathname === '/login') return <LoginPage />;
  return (
    <ProtectedRoute>
      <Suspense fallback={<AdminLoading compact label="جارٍ التحميل" />}>
        <AuthenticatedApp />
      </Suspense>
    </ProtectedRoute>
  );
}

export default App;
