'use client';
import { useAuthStore } from '@/store/auth.store';

/** لقطة الجلسة الحالية لتطبيق الويب (التوكن يُدار في api-client عبر setAuthToken). */
export function getWebSession() {
  const { token, actorType, destination, home, user } = useAuthStore.getState();
  return { token, actorType, destination, home, user };
}

export function clearWebSession() {
  useAuthStore.getState().clear();
}
