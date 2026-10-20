import React from 'react';
import { Outlet, Link, useLocation } from 'react-router-dom';
import { 
  LayoutDashboard, 
  Package, 
  ShoppingBag, 
  Users, 
  Warehouse, 
  Coins, 
  BarChart3, 
  ShieldCheck, 
  LogOut 
} from 'lucide-react';
import { useAuthStore } from '../stores/auth.store';
import { useCan } from '../hooks/useCan';

export function AdminLayout() {
  const location = useLocation();
  const { user, logout } = useAuthStore();
  const canViewReports = useCan('reports.view');
  const canViewTreasury = useCan('treasuries.view');

  const navItems = [
    { label: 'الرئيسية', path: '/admin', icon: LayoutDashboard },
    { label: 'المنتجات', path: '/admin/products', icon: Package },
    { label: 'الطلبات', path: '/admin/orders', icon: ShoppingBag },
    { label: 'العملاء', path: '/admin/customers', icon: Users },
    { label: 'المخزون', path: '/admin/inventory', icon: Warehouse },
    ...(canViewTreasury ? [{ label: 'الخزينة والمصروفات', path: '/admin/treasury', icon: Coins }] : []),
    ...(canViewReports ? [{ label: 'التقارير', path: '/admin/reports', icon: BarChart3 }] : []),
  ];

  return (
    <div className="flex min-h-screen bg-zinc-100" dir="rtl">
      {/* Sidebar */}
      <aside className="w-64 bg-zinc-900 text-white flex flex-col fixed inset-y-0 right-0 z-30">
        <div className="h-16 flex items-center px-6 border-b border-zinc-800">
          <span className="text-xl font-bold text-brand-400">لوحة الإدارة</span>
        </div>
        <nav className="flex-1 px-4 py-6 space-y-1 overflow-y-auto">
          {navItems.map((item) => {
            const Icon = item.icon;
            const isActive = location.pathname === item.path;
            return (
              <Link
                key={item.path}
                to={item.path}
                className={`flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm font-medium transition-colors ${
                  isActive ? 'bg-brand-600 text-white' : 'text-zinc-400 hover:text-white hover:bg-zinc-800'
                }`}
              >
                <Icon className="w-5 h-5" />
                <span>{item.label}</span>
              </Link>
            );
          })}
        </nav>
        <div className="p-4 border-t border-zinc-800 flex items-center justify-between text-xs text-zinc-400">
          <div className="flex items-center gap-2">
            <ShieldCheck className="w-4 h-4 text-emerald-400" />
            <span>{user?.name || 'مدير النظام'}</span>
          </div>
          <button onClick={logout} className="hover:text-red-400 transition-colors">
            <LogOut className="w-4 h-4" />
          </button>
        </div>
      </aside>

      {/* Main Content Area */}
      <div className="flex-1 mr-64 flex flex-col min-h-screen">
        <header className="h-16 bg-white border-b border-zinc-200 px-8 flex items-center justify-between sticky top-0 z-20">
          <h2 className="text-lg font-semibold text-zinc-800">منصة الفريد - لوحة التحكم العامة</h2>
          <div className="flex items-center gap-4">
            <Link to="/supplier" className="text-xs bg-zinc-100 hover:bg-zinc-200 text-zinc-700 px-3 py-1.5 rounded-lg transition-colors">
              التبديل لبوابة المورد
            </Link>
          </div>
        </header>
        <main className="flex-1 p-8">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
