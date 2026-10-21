'use client';
import { RequireActor } from '@/components/auth/RequireActor';

export default function WholesalePortalLayout({ children }: { children: React.ReactNode }) {
  return <RequireActor allow={['wholesale']}>{children}</RequireActor>;
}
