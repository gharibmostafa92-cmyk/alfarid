import { authEndpoints, createApiClient, defaultApiClient, resolveServiceUrl } from '@alfarid/api-client';
import { useAuthStore } from '@/store/auth.store';
import { getCartSession, restoreGuestCartSession, clearGuestCartSession } from '@/stores/cart-store';

async function revokePreviousSession(previous: string | null | undefined, nextToken?: string | null, timeoutMs = 3000) {
  if (!previous || previous === nextToken) return;
  try {
    await createApiClient({ getToken: () => previous, onUnauthorized: () => {} })
      .post('/api/v1/auth/logout', {}, { timeout: timeoutMs });
  } catch { /* The previous session may already have expired. */ }
}

export async function loginUnified(login: string, password: string, redirect?: string | null, turnstileToken?: string | null) {
  const guestSession = getCartSession();
  const previousToken = useAuthStore.getState().token;
  const res = await authEndpoints.login({ login, password, redirect: redirect ?? undefined, ...(turnstileToken ? { turnstile_token: turnstileToken } : {}) }, guestSession);

  if (res.redirect.app === 'backoffice') {
    await revokePreviousSession(previousToken, res.token, 1500);
    // Clear local state after revoking previous active session when switching to backoffice.
    useAuthStore.getState().clear();
    // Pass single-use handoff code in URL fragment to prevent exposure in HTTP logs or Referers.
    const url = `${resolveServiceUrl(process.env.NEXT_PUBLIC_BACKOFFICE_URL ?? 'http://localhost:5173')}/auth/callback#code=${encodeURIComponent(res.handoff_code!)}`;
    window.location.assign(url);
    return null;
  }

  void revokePreviousSession(previousToken, res.token, 3000);

  useAuthStore.getState().setSession({
    token: res.token!, actorType: res.actor.type as 'customer' | 'supplier',
    destination: res.actor.subtype, home: res.redirect.path, user: res.data,
  });
  // Preserve the guest cart session unless a retail customer confirmed guest-cart merge.
  if (res.actor.type === 'customer' && res.actor.subtype === 'retail' && res.cart_merged === true) {
    clearGuestCartSession();
  } else if (guestSession) {
    restoreGuestCartSession(guestSession);
  }
  return res.redirect.path;
}

export async function logoutWeb(next = '/auth/login') {
  try { await authEndpoints.logout(); } catch { /* Clear local credentials even when the server is unreachable. */ }
  finally { useAuthStore.getState().clear(); window.location.href = next; }
}

export interface RegistrationInput {
  name: string;
  phone: string;
  email?: string;
  password: string;
  phone_verification_token?: string;
  company_name?: string;
  tax_number?: string;
  commercial_register?: string;
}

interface RegistrationResponse {
  cart_merged?: boolean;
  token: string;
  data: Record<string, unknown>;
}

export async function registerWeb(input: RegistrationInput, wholesale: boolean, documents: File[] = []) {
  let body: RegistrationInput | FormData = input;
  if (wholesale) {
    body = new FormData();
    Object.entries(input).forEach(([key, value]) => {
      if (value !== undefined && value !== '') (body as FormData).append(key, value);
    });
    documents.forEach((file) => (body as FormData).append('documents[]', file));
  }
  const session = getCartSession();
  const response = await defaultApiClient.post<RegistrationResponse>(
    wholesale ? '/api/v1/wholesale/auth/register' : '/api/v1/store/auth/register', body,
    { headers: {
      ...(wholesale ? { 'Content-Type': 'multipart/form-data' } : {}),
      ...(!wholesale && session ? { 'X-Cart-Session': session } : {}),
    } },
  );
  const home = wholesale ? '/wholesale/application-status' : '/shop';
  const previousToken = useAuthStore.getState().token;
  void revokePreviousSession(previousToken, response.data.token, 3000);
  useAuthStore.getState().setSession({
    token: response.data.token, actorType: 'customer',
    destination: wholesale ? 'wholesale_pending' : 'retail', home, user: response.data.data,
  });
  // Clear the guest cart only when the server explicitly confirms the merge.
  // An absent cart_merged flag means the merge was skipped or failed: keep
  // the guest session so its lines are not silently lost.
  if (!wholesale && (response.data.cart_merged === true || !session)) {
    clearGuestCartSession();
  } else if (session) {
    restoreGuestCartSession(session);
  }
  return home;
}

function recoveryIdentifier(login: string) {
  const value = login.trim();
  if (value.includes('@')) return { email: value.toLowerCase() };
  return { phone: value };
}

export async function requestPasswordReset(login: string) {
  const response = await defaultApiClient.post<{ message: string }>(
    '/api/v1/store/auth/forgot-password', recoveryIdentifier(login),
  );
  return response.data;
}

export async function resetWebPassword(login: string, token: string, password: string, confirmation: string) {
  await defaultApiClient.post('/api/v1/store/auth/reset-password', {
    ...recoveryIdentifier(login), token: token.trim(), password, password_confirmation: confirmation,
  });
}

export async function getRegistrationOptions() {
  const response = await defaultApiClient.get<{ data: { phone_verification_required: boolean; turnstile_site_key: string | null } }>(
    '/api/v1/store/auth/registration-options',
  );
  return response.data.data;
}

export async function sendPhoneVerification(phone: string, turnstileToken: string) {
  const response = await defaultApiClient.post<{ message: string }>('/api/v1/store/auth/phone-verification/send', {
    phone, turnstile_token: turnstileToken,
  });
  return response.data;
}

export async function verifyPhone(phone: string, code: string) {
  const response = await defaultApiClient.post<{ phone_verification_token: string }>(
    '/api/v1/store/auth/phone-verification/verify', { phone, code },
  );
  return response.data.phone_verification_token;
}
