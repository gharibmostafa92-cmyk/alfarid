import { RequireActor } from '@/components/auth/RequireActor';
import { PortalPasswordPage } from '@/components/auth/PortalPasswordPage';

export default function PasswordPage() {
  return <RequireActor allow={['retail', 'wholesale', 'wholesale_pending']}>
    <PortalPasswordPage channel="customer" />
  </RequireActor>;
}
