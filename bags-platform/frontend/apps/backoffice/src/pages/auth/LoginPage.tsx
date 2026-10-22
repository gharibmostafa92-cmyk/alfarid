import { useEffect } from 'react';
import { resolveServiceUrl } from '@alfarid/api-client';

export default function LoginPage() {
  useEffect(() => {
    window.location.replace(`${resolveServiceUrl(import.meta.env.VITE_WEB_URL ?? 'http://localhost:3000')}/auth/login`);
  }, []);
  return null;
}
