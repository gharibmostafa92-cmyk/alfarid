import { defaultApiClient } from '@alfarid/api-client';
import { ProtectedRoute } from './components/auth/ProtectedRoute';
import AuthCallbackPage from './pages/auth/AuthCallbackPage';
import LoginPage from './pages/auth/LoginPage';
import SessionProbePage from './pages/auth/SessionProbePage';
import { FinancialDashboard } from '@alfarid/ui';
import { useCallback, useEffect, useState } from 'react';
import { useAuthStore } from './stores/auth.store';
import CategoriesMockPage from './pages/admin/categories-mock/CategoriesMockPage';
import PosPage from './pages/pos/PosPage';

defaultApiClient.defaults.baseURL = import.meta.env.VITE_API_URL ?? 'http://localhost:8000';

export function App() {
  if (window.location.pathname === '/auth/session') return <SessionProbePage />;
  if (window.location.pathname === '/auth/callback') return <AuthCallbackPage />;
  if (window.location.pathname === '/login') return <LoginPage />;
  return <ProtectedRoute><AuthenticatedApp /></ProtectedRoute>;
}

function AuthenticatedApp() {
  const user = useAuthStore((state) => state.user);
  const logoutRemote = useAuthStore((state) => state.logoutRemote);
  const readRoute = () => window.location.hash.replace(/^#/, '').split('?')[0]
    || window.location.pathname;
  const [route, setRoute] = useState(readRoute);
  const can = useCallback((permission: string) =>
    !!user?.permissions.some((granted) => granted === '*' || granted === permission), [user]);
  const cashierOnly = user?.roles.length === 1 && user.roles[0] === 'cashier';
  useEffect(() => {
    const changed = () => setRoute(readRoute());
    window.addEventListener('hashchange', changed);
    window.addEventListener('popstate', changed);
    return () => {
      window.removeEventListener('hashchange', changed);
      window.removeEventListener('popstate', changed);
    };
  }, []);
  useEffect(() => {
    if (cashierOnly && !['/', '/dashboard', '/pos'].includes(route)) window.location.replace('/dashboard');
    else if (!['/', '/dashboard', '/categories', '/pos'].includes(route)) window.location.replace('/dashboard');
  }, [cashierOnly, route]);
  if (cashierOnly && !['/', '/dashboard', '/pos'].includes(route)) return null;
  if (route === '/pos') return <PosPage />;
  const categoriesActive = route === '/categories';
  return <FinancialDashboard
    onLogout={logoutRemote}
    categoriesActive={categoriesActive}
    categoriesPage={<CategoriesMockPage can={can} embedded />}
    onOpenCategories={!cashierOnly && can('categories.view')
      ? () => { window.location.hash = '/categories'; }
      : undefined}
    onCloseCategories={() => { window.location.hash = '/dashboard'; }}
  />;
}

export default App;
