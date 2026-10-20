import React from 'react';
import { Outlet, Link, useLocation } from 'react-router-dom';
import { FileText, Truck, Receipt, ArrowRightLeft, LogOut } from 'lucide-react';
import { useAuthStore } from '../stores/auth.store';

export function SupplierLayout() {
  const location = useLocation();
  const { logout } = useAuthStore();

  const navItems = [
    { label: 'أوامر الشراء (POs)', path: '/supplier', icon: FileText },
    { label: 'إشعارات التوريد والشحن', path: '/supplier/deliveries', icon: Truck },
    { label: 'فواتير التوريد', path: '/supplier/invoices', icon: Receipt },
  ];

  return (
    <div className="flex min-h-screen bg-slate-100" dir="rtl">
      {/* Sidebar */}
      <aside className="w-64 bg-slate-900 text-white flex flex-col fixed inset-y-0 right-0 z-30">
        <div className="h-16 flex items-center px-6 border-b border-slate-800">
          <span className="text-xl font-bold text-amber-400">بوابة الموردين</span>
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
                  isActive ? 'bg-amber-600 text-white' : 'text-slate-400 hover:text-white hover:bg-slate-800'
                }`}
              >
                <Icon className="w-5 h-5" />
                <span>{item.label}</span>
              </Link>
            );
          })}
        </nav>
        <div className="p-4 border-t border-slate-800 flex items-center justify-between text-xs text-slate-400">
          <button onClick={logout} className="flex items-center gap-2 hover:text-red-400 transition-colors">
            <LogOut className="w-4 h-4" />
            <span>تسجيل الخروج</span>
          </button>
        </div>
      </aside>

      {/* Main Content Area */}
      <div className="flex-1 mr-64 flex flex-col min-h-screen">
        <header className="h-16 bg-white border-b border-slate-200 px-8 flex items-center justify-between sticky top-0 z-20">
          <h2 className="text-lg font-semibold text-slate-800">إدارة التوريدات والمشتريات</h2>
          <Link to="/admin" className="text-xs bg-slate-100 hover:bg-slate-200 text-slate-700 px-3 py-1.5 rounded-lg flex items-center gap-1.5 transition-colors">
            <ArrowRightLeft className="w-3.5 h-3.5" />
            <span>العودة للوحة الإدارة</span>
          </Link>
        </header>
        <main className="flex-1 p-8">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
