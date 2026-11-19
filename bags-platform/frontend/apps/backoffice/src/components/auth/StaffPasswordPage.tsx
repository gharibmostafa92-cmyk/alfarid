import { authEndpoints } from '@alfarid/api-client';
import { ChangePasswordForm } from '@alfarid/ui';
import { useAuthStore } from '../../stores/auth.store';

export function StaffPasswordPage({ channel, embedded = false }: { channel: 'admin' | 'pos'; embedded?: boolean }) {
  const { user, token, setUser } = useAuthStore();
  if (!token || !user) return null;
  const version = user.version;
  if (version == null) return <p role="alert">حدّث الصفحة لتحميل بيانات حسابك.</p>;
  return <>
    {!embedded && <a href={channel === 'pos' ? '#/pos' : '#/dashboard'}>العودة</a>}
    <ChangePasswordForm key={token} minimum={12} staff onSubmit={async ({ current_password, password }, signal) => {
      const result = await authEndpoints.changeStaffPassword(channel, {
        current_password, password, expected_version: version,
      }, token, signal);
      const session = useAuthStore.getState();
      if (session.token === token && session.user?.id === user.id) {
        setUser({ ...session.user, version: result.version });
      }
    }} />
  </>;
}
