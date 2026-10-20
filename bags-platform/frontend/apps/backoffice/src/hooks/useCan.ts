import { useAuthStore } from '../stores/auth.store';
import { hasPermission, hasAnyPermission, hasAllPermissions } from '@alfarid/utils';

/**
 * Custom hook to check if current staff user has permission for an action/screen.
 * Example: const canCancel = useCan('orders.cancel');
 */
export function useCan(permission: string): boolean {
  const user = useAuthStore((state) => state.user);
  return hasPermission(user?.permissions, permission);
}

export function useCanAny(permissions: string[]): boolean {
  const user = useAuthStore((state) => state.user);
  return hasAnyPermission(user?.permissions, permissions);
}

export function useCanAll(permissions: string[]): boolean {
  const user = useAuthStore((state) => state.user);
  return hasAllPermissions(user?.permissions, permissions);
}
