import React from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AdminLayout } from './layouts/AdminLayout';
import { SupplierLayout } from './layouts/SupplierLayout';
import { AdminDashboardPage } from './pages/admin/DashboardPage';
import { ProductsPage } from './pages/admin/ProductsPage';
import { ProductFormPage } from './pages/admin/ProductFormPage';
import { CategoriesPage } from './pages/admin/CategoriesPage';
import { BrandsPage } from './pages/admin/BrandsPage';
import { BranchesPage } from './pages/admin/BranchesPage';
import { BranchDetailPage } from './pages/admin/BranchDetailPage';
import { UsersPage } from './pages/admin/UsersPage';
import { RolesPage } from './pages/admin/RolesPage';
import { SettingsPage } from './pages/admin/SettingsPage';
import { KitPage } from './pages/admin/KitPage';
import { SupplierPurchaseOrdersPage } from './pages/supplier/PurchaseOrdersPage';
import { LoginPage } from './pages/auth/LoginPage';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60 * 5,
      refetchOnWindowFocus: false,
    },
  },
});

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <BrowserRouter>
        <Routes>
          <Route path="/login" element={<LoginPage />} />

          {/* Admin routes with AdminLayout */}
          <Route path="/admin" element={<AdminLayout />}>
            <Route index element={<AdminDashboardPage />} />
            
            {/* Catalog */}
            <Route path="products" element={<ProductsPage />} />
            <Route path="products/new" element={<ProductFormPage />} />
            <Route path="products/:id" element={<ProductFormPage />} />
            <Route path="categories" element={<CategoriesPage />} />
            <Route path="brands" element={<BrandsPage />} />

            {/* Operations */}
            <Route path="branches" element={<BranchesPage />} />
            <Route path="branches/:id" element={<BranchDetailPage />} />
            <Route path="orders" element={<div className="p-8 bg-white border border-zinc-200 rounded-2xl shadow-sm"><h2 className="text-lg font-bold">شاشة الطلبات والعمليات</h2><p className="text-xs text-zinc-500 mt-1">قيد التطوير في المرحلة القادمة.</p></div>} />
            <Route path="inventory" element={<div className="p-8 bg-white border border-zinc-200 rounded-2xl shadow-sm"><h2 className="text-lg font-bold">حركات المخزون والمستودعات</h2><p className="text-xs text-zinc-500 mt-1">قيد التطوير في المرحلة القادمة.</p></div>} />
            <Route path="customers" element={<div className="p-8 bg-white border border-zinc-200 rounded-2xl shadow-sm"><h2 className="text-lg font-bold">إدارة العملاء وطلبات الجملة</h2><p className="text-xs text-zinc-500 mt-1">قيد التطوير في المرحلة القادمة.</p></div>} />

            {/* Management & System */}
            <Route path="users" element={<UsersPage />} />
            <Route path="roles" element={<RolesPage />} />
            <Route path="settings" element={<SettingsPage />} />

            {/* Design reference */}
            <Route path="kit" element={<KitPage />} />
          </Route>

          {/* Supplier routes with SupplierLayout */}
          <Route path="/supplier" element={<SupplierLayout />}>
            <Route index element={<SupplierPurchaseOrdersPage />} />
            <Route path="deliveries" element={<div className="p-8 bg-white border border-zinc-200 rounded-2xl shadow-sm"><h2 className="text-lg font-bold">إشعارات الشحن والتسليم</h2></div>} />
            <Route path="invoices" element={<div className="p-8 bg-white border border-zinc-200 rounded-2xl shadow-sm"><h2 className="text-lg font-bold">فواتير التوريد والمستحقات</h2></div>} />
          </Route>

          {/* Fallback */}
          <Route path="*" element={<Navigate to="/admin" replace />} />
        </Routes>
      </BrowserRouter>
    </QueryClientProvider>
  );
}
export default App;
