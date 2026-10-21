'use client';
import { FinancialDashboard } from '@alfarid/ui';
import { logoutWeb } from '@/services/auth.service';

export default function SupplierPage() {
  return <FinancialDashboard onLogout={logoutWeb} />;
}
