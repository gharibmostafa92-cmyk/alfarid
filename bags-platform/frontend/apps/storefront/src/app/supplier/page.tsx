'use client';
import { useAuthStore } from '@/store/auth.store';
import { logoutWeb } from '@/services/auth.service';

export default function SupplierPage() {
  const user = useAuthStore((s) => s.user) as { name?: string } | null;

  return (
    <div className="container mx-auto px-4 py-8">
      <h1 className="text-2xl font-bold">مرحبًا {user?.name ?? ''}</h1>
      <button
        type="button"
        onClick={() => logoutWeb()}
        className="mt-4 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white"
      >
        تسجيل الخروج
      </button>
    </div>
  );
}
