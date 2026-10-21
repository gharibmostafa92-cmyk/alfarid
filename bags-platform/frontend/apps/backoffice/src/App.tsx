import AdminApp from './AdminApp';
import { defaultApiClient } from '@alfarid/api-client';
import { ProtectedRoute } from './components/auth/ProtectedRoute';
import AuthCallbackPage from './pages/auth/AuthCallbackPage';
import LoginPage from './pages/auth/LoginPage';

defaultApiClient.defaults.baseURL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000';

export function App() {
  if (window.location.pathname === '/auth/callback') return <AuthCallbackPage />;
  if (window.location.pathname === '/login') return <LoginPage />;
  return <ProtectedRoute><AdminApp /></ProtectedRoute>;
}

export default App;
