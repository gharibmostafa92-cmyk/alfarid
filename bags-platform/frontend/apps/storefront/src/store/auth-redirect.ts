const protectedPrefixes = ['/account', '/supplier', '/wholesale'];

export function unauthorizedLoginRedirect(pathname: string, search: string, hasSession: boolean): string | null {
  if (pathname.startsWith('/auth/') || pathname === '/wholesale/register') return null;

  // Guest checkout stays public. An expired customer session must reauthenticate
  // so the server-owned customer cart can be restored before checkout continues.
  const checkoutNeedsReauthentication = hasSession && pathname === '/checkout';
  const protectedPage = protectedPrefixes.some((prefix) => pathname === prefix || pathname.startsWith(`${prefix}/`));
  if (!protectedPage && !checkoutNeedsReauthentication) return null;

  return `/auth/login?redirect=${encodeURIComponent(pathname + search)}`;
}
