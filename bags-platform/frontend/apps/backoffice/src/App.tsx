import React from 'react';
import { BrowserRouter, Routes, Route, Navigate } from 'react-router-dom';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { AdminLayout } from './layouts/AdminLayout';
import { SupplierLayout } from './layouts/SupplierLayout';
import { AdminDashboardPage } from './pages/admin/DashboardPage';
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
            <Route path="products" element={<div className="p-4 bg-white rounded-xl">شاشة المنتجات وإدارة المخزون</div>} />
            <Route path="orders" element={<div className="p-4 bg-white rounded-xl">شاشة الطلبات والعمليات</div>} />
            <Route path="customers" element={<div className="p-4 bg-white rounded-xl">شاشة إدارة العملاء والجملة</div>} />
            <Route path="inventory" element={<div className="p-4 bg-white rounded-xl">حركات المخزون والمستودعات</div>} />
            <Route path="treasury" element={<div className="p-4 bg-white rounded-xl">الخزينة والمصروفات والتحويلات</div>} />
            <Route path="reports" element={<div className="p-4 bg-white rounded-xl">تقارير المبيعات والأرباح</div>} />
          </Route>

          {/* Supplier routes with SupplierLayout */}
          <Route path="/supplier" element={<SupplierLayout />}>
            <Route index element={<SupplierPurchaseOrdersPage />} />
            <Route path="deliveries" element={<div className="p-4 bg-white rounded-xl">إشعارات الشحن والتسليم</div>} />
            <Route path="invoices" element={<div className="p-4 bg-white rounded-xl">فواتير التوريد والمستحقات</div>} />
          </Route>

          {/* Fallback */}
          <Route path="*" element={<Navigate to="/admin" replace />} />
        </Routes>
      </BrowserRouter>
    </QueryClientProvider>
  );
}
