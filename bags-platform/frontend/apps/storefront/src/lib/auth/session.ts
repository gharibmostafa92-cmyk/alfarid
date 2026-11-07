'use client';
import { useAuthStore } from '@/store/auth.store';

/** Current web application session snapshot. Tokens are managed via api-client. */
export function getWebSession() {
  const { token, actorType, destination, home, user } = useAuthStore.getState();
  return { token, actorType, destination, home, user };
}

export function clearWebSession() {
  useAuthStore.getState().clear();
}
