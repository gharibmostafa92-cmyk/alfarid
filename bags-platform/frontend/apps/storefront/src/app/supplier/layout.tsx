'use client';
import { RequireActor } from '@/components/auth/RequireActor';

export default function SupplierLayout({ children }: { children: React.ReactNode }) {
  return <RequireActor allow={['supplier']}>{children}</RequireActor>;
}
