import { RequireActor } from '@/components/auth/RequireActor';
import { PortalPasswordPage } from '@/components/auth/PortalPasswordPage';

export default function PasswordPage() {
  return (
    <RequireActor allow={['retail', 'wholesale', 'wholesale_pending']}>
      <main id="main-content" className="min-h-[calc(100vh-4rem)] bg-[#f3f5f7] py-4">
        <PortalPasswordPage channel="customer" />
      </main>
    </RequireActor>
  );
}
