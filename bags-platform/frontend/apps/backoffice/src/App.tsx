import { hasPermission } from '@alfarid/utils';
import { defaultApiClient, resolveServiceUrl } from '@alfarid/api-client';
import { ProtectedRoute } from './components/auth/ProtectedRoute';
import AuthCallbackPage from './pages/auth/AuthCallbackPage';
import LoginPage from './pages/auth/LoginPage';
import SessionProbePage from './pages/auth/SessionProbePage';
import { FinancialDashboard } from '@alfarid/ui';
import { lazy, Suspense, useCallback, useEffect, useState } from 'react';
import { useAuthStore } from './stores/auth.store';
import CategoriesMockPage from './pages/admin/categories-mock/CategoriesMockPage';
import PosPage from './pages/pos/PosPage';
import CatalogMockPage from './pages/admin/catalog-mock/CatalogMockPage';
import AdminMockPage from './pages/admin/admin-mock/AdminMockPage';
const InventoryPage = lazy(() => import('./pages/admin/inventory-mock/InventoryPage'));

defaultApiClient.defaults.baseURL = resolveServiceUrl(import.meta.env.VITE_API_URL ?? 'http://localhost:8000');

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
    hasPermission(user?.permissions, permission), [user]);
  const cashierOnly = user?.roles.length === 1 && user.roles[0] === 'cashier';
  useEffect(() => {
    // Stocktake navigation guards may synchronously restore the accepted hash.
    const changed = () => queueMicrotask(() => setRoute(readRoute()));
    window.addEventListener('hashchange', changed);
    window.addEventListener('popstate', changed);
    return () => {
      window.removeEventListener('hashchange', changed);
      window.removeEventListener('popstate', changed);
    };
  }, []);
  useEffect(() => {
    if (cashierOnly && !['/', '/dashboard', '/pos'].includes(route)) window.location.replace('/dashboard');
    else if (!['/', '/dashboard', '/categories', '/dealers', '/production', '/pos', '/users', '/roles', '/settings'].includes(route) && !/^\/products(?:\/(?:new|[0-9]+))?$/.test(route) && !/^\/inventory(?:\/[a-z-]+)?$/.test(route)) window.location.replace('/dashboard');
  }, [cashierOnly, route]);
  if (cashierOnly && !['/', '/dashboard', '/pos'].includes(route)) return null;
  if (route === '/pos') return <PosPage />;
  const categoriesActive = route === '/categories';
  const inventoryActive = route === '/inventory' || route.startsWith('/inventory/');
  const adminActive = route === '/users' ? 'users' : route === '/roles' ? 'roles' : route === '/settings' ? 'settings' : null;
  const catalogActive = inventoryActive ? 'inventory' : route.startsWith('/products') ? 'products' : route === '/dealers' ? 'dealers' : route === '/production' ? 'production' : adminActive;
  return <FinancialDashboard
    onLogout={logoutRemote}
    catalogActive={catalogActive}
    catalogPage={inventoryActive ? (can('inventory.view') ? <Suspense fallback={<div dir="rtl" className="p-6">جارٍ تحميل المخزون…</div>}><InventoryPage can={can}/></Suspense> : <div dir="rtl" className="p-6">ليس لديك صلاحية عرض المخزون.</div>) : adminActive ? <AdminMockPage key={adminActive} page={adminActive} can={can} /> : catalogActive && <CatalogMockPage key={catalogActive} can={can} page={catalogActive} />}
    catalogNav={!cashierOnly ? [
      ...(can('inventory.view') ? [{ id: 'inventory', label: 'المخزون', onOpen: () => { window.location.hash = '/inventory/stock'; } }] : []),
      ...(can('products.view') ? [{ id: 'products', label: 'المنتجات', onOpen: () => { window.location.hash = '/products'; } }] : []),
      ...(can('customers.view') ? [{ id: 'dealers', label: 'التجار والموزعون', onOpen: () => { window.location.hash = '/dealers'; } }] : []),
      ...(can('inventory.view') ? [{ id: 'production', label: 'استلام الإنتاج', onOpen: () => { window.location.hash = '/production'; } }] : []),
      ...((can('users.view') || can('users.manage')) ? [{ id: 'users', label: 'المستخدمون', onOpen: () => { window.location.hash = '/users'; } }] : []),
      ...((can('roles.view') || can('roles.manage')) ? [{ id: 'roles', label: 'الأدوار', onOpen: () => { window.location.hash = '/roles'; } }] : []),
      ...((can('settings.view') || can('settings.manage')) ? [{ id: 'settings', label: 'الإعدادات', onOpen: () => { window.location.hash = '/settings'; } }] : []),
    ] : []}
    onCloseCatalog={() => { window.location.hash = '/dashboard'; }}
    categoriesActive={categoriesActive}
    categoriesPage={<CategoriesMockPage can={can} embedded />}
    onOpenCategories={!cashierOnly && can('categories.view')
      ? () => { window.location.hash = '/categories'; }
      : undefined}
    onCloseCategories={() => { window.location.hash = '/dashboard'; }}
  />;
}

export default App;
