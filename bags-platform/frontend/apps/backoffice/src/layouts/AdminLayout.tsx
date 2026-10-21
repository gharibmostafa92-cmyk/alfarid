import React from 'react';
import { Outlet, Link, useLocation } from 'react-router-dom';
import {
  LayoutDashboard,
  Package,
  Layers,
  Tag,
  Building2,
  Users,
  ShieldCheck,
  Settings,
  Component,
  LogOut,
  ShoppingBag,
  Warehouse,
  ExternalLink,
} from 'lucide-react';
import { useAuthStore } from '../stores/auth.store';

interface NavGroup {
  title: string;
  items: {
    label: string;
    path: string;
    icon: React.ComponentType<{ className?: string }>;
  }[];
}

export function AdminLayout() {
  const location = useLocation();
  const { user, logoutRemote } = useAuthStore();

  const navGroups: NavGroup[] = [
    {
      title: 'نظرة عامة',
      items: [{ label: 'الرئيسية', path: '/admin', icon: LayoutDashboard }],
    },
    {
      title: 'الكتالوج',
      items: [
        { label: 'المنتجات', path: '/admin/products', icon: Package },
        { label: 'الأقسام', path: '/admin/categories', icon: Layers },
        { label: 'البراندات', path: '/admin/brands', icon: Tag },
      ],
    },
    {
      title: 'التشغيل والمخزون',
      items: [
        { label: 'الفروع والمخازن', path: '/admin/branches', icon: Building2 },
        { label: 'الطلبات', path: '/admin/orders', icon: ShoppingBag },
        { label: 'المخزون', path: '/admin/inventory', icon: Warehouse },
      ],
    },
    {
      title: 'الإدارة والنظام',
      items: [
        { label: 'المستخدمون', path: '/admin/users', icon: Users },
        { label: 'الأدوار والصلاحيات', path: '/admin/roles', icon: ShieldCheck },
        { label: 'الإعدادات العامة', path: '/admin/settings', icon: Settings },
      ],
    },
    {
      title: 'مرجع التصميم',
      items: [
        { label: 'دليل المكونات', path: '/admin/kit', icon: Component },
      ],
    },
  ];

  return (
    <div className="flex min-h-screen bg-zinc-100 font-sans" dir="rtl">
      {/* Sidebar */}
      <aside className="w-64 bg-zinc-950 text-white flex flex-col fixed inset-y-0 right-0 z-30 border-l border-zinc-800 shadow-xl">
        {/* Brand Header */}
        <div className="h-16 flex items-center justify-between px-6 border-b border-zinc-800/80">
          <div className="flex items-center gap-2.5">
            <div className="w-8 h-8 rounded-lg bg-brand-600 flex items-center justify-center text-white font-black text-sm shadow-md shadow-brand-900/40">
              AF
            </div>
            <div>
              <span className="text-sm font-black tracking-tight text-white block">
                منصة الفريد
              </span>
              <span className="text-[10px] text-zinc-400 block -mt-1 font-mono">
                ADMIN BACKOFFICE
              </span>
            </div>
          </div>
        </div>

        {/* Navigation list */}
        <nav className="flex-1 px-3 py-4 space-y-6 overflow-y-auto">
          {navGroups.map((group) => (
            <div key={group.title} className="space-y-1">
              <div className="px-3 text-[10px] font-bold text-zinc-500 uppercase tracking-wider mb-1">
                {group.title}
              </div>
              {group.items.map((item) => {
                const Icon = item.icon;
                const isActive =
                  item.path === '/admin'
                    ? location.pathname === '/admin'
                    : location.pathname.startsWith(item.path);

                return (
                  <Link
                    key={item.path}
                    to={item.path}
                    className={`flex items-center gap-3 px-3 py-2 rounded-xl text-xs font-semibold transition-all ${
                      isActive
                        ? 'bg-brand-600 text-white shadow-sm shadow-brand-900/20'
                        : 'text-zinc-400 hover:text-white hover:bg-zinc-900'
                    }`}
                  >
                    <Icon className={`w-4 h-4 ${isActive ? 'text-white' : 'text-zinc-400'}`} />
                    <span>{item.label}</span>
                  </Link>
                );
              })}
            </div>
          ))}
        </nav>

        {/* User Footer Profile */}
        <div className="p-3 border-t border-zinc-800/80 bg-zinc-900/40 flex items-center justify-between">
          <div className="flex items-center gap-2.5 min-w-0">
            <div className="w-8 h-8 rounded-full bg-zinc-800 border border-zinc-700 flex items-center justify-center text-xs font-bold text-zinc-300 shrink-0">
              {user?.name ? user.name[0] : 'أ'}
            </div>
            <div className="min-w-0">
              <div className="text-xs font-bold text-zinc-200 truncate">
                {user?.name || 'أحمد الفريد'}
              </div>
              <div className="text-[10px] text-zinc-400 truncate">
                مدير النظام
              </div>
            </div>
          </div>
          <button
            onClick={() => logoutRemote()}
            className="p-1.5 text-zinc-400 hover:text-red-400 hover:bg-zinc-800 rounded-lg transition-colors"
            title="تسجيل الخروج"
          >
            <LogOut className="w-4 h-4" />
          </button>
        </div>
      </aside>

      {/* Main Content Area */}
      <div className="flex-1 mr-64 flex flex-col min-h-screen">
        {/* Top Navbar */}
        <header className="h-16 bg-white border-b border-zinc-200 px-8 flex items-center justify-between sticky top-0 z-20 shadow-xs">
          <div className="flex items-center gap-3 text-xs text-zinc-500 font-medium">
            <span className="w-2 h-2 rounded-full bg-emerald-500 inline-block animate-pulse" />
            <span>نظام إدارة العمليات والكتالوج المركزي</span>
          </div>

          <div className="flex items-center gap-3">
            <Link
              to="/supplier"
              className="inline-flex items-center gap-1.5 text-xs font-semibold text-zinc-700 bg-zinc-100 hover:bg-zinc-200 px-3 py-1.5 rounded-lg transition-colors border border-zinc-200"
            >
              <ExternalLink className="w-3.5 h-3.5 text-zinc-500" />
              <span>بوابة المورد</span>
            </Link>
          </div>
        </header>

        {/* Content Body */}
        <main className="flex-1 p-8 max-w-7xl w-full mx-auto">
          <Outlet />
        </main>
      </div>
    </div>
  );
}
