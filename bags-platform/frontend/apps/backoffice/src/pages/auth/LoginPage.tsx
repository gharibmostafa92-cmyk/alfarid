import { useEffect } from 'react';

export default function LoginPage() {
  useEffect(() => {
    window.location.replace(`${import.meta.env.VITE_WEB_URL}/auth/login`);
  }, []);
  return null;
}
