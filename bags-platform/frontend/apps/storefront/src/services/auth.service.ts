import { authEndpoints, defaultApiClient } from '@alfarid/api-client';
import { useAuthStore } from '@/store/auth.store';

const CART_KEY = 'cart_session';

export async function loginUnified(login: string, password: string, redirect?: string | null) {
  const res = await authEndpoints.login({ login, password, redirect: redirect ?? undefined },
    typeof window !== 'undefined' ? localStorage.getItem(CART_KEY) : null);

  if (res.redirect.app === 'backoffice') {
    useAuthStore.getState().clear();
    const url = `${process.env.NEXT_PUBLIC_BACKOFFICE_URL ?? 'http://localhost:5173'}/auth/callback?code=${encodeURIComponent(res.handoff_code!)}`;
    window.location.assign(url);
    return null;
  }

  useAuthStore.getState().setSession({
    token: res.token!, actorType: res.actor.type as 'customer' | 'supplier',
    destination: res.actor.subtype, home: res.redirect.path, user: res.data,
  });
  if (res.actor.type === 'customer') localStorage.removeItem(CART_KEY);
  return res.redirect.path;
}

export async function logoutWeb() {
  try { await authEndpoints.logout(); } finally { useAuthStore.getState().clear(); window.location.href = '/auth/login'; }
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
  const session = localStorage.getItem(CART_KEY);
  const response = await defaultApiClient.post<RegistrationResponse>(
    wholesale ? '/api/v1/wholesale/auth/register' : '/api/v1/store/auth/register', body,
    { headers: {
      ...(wholesale ? { 'Content-Type': 'multipart/form-data' } : {}),
      ...(!wholesale && session ? { 'X-Cart-Session': session } : {}),
    } },
  );
  const home = wholesale ? '/wholesale/application-status' : '/';
  useAuthStore.getState().setSession({
    token: response.data.token, actorType: 'customer',
    destination: wholesale ? 'wholesale_pending' : 'retail', home, user: response.data.data,
  });
  if (!wholesale) localStorage.removeItem(CART_KEY);
  return home;
}

function recoveryIdentifier(login: string) {
  const value = login.trim();
  return value.includes('@') ? { email: value } : { phone: value };
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

export async function sendPhoneVerification(phone: string) {
  const response = await defaultApiClient.post<{ message: string }>('/api/v1/store/auth/phone-verification/send', { phone });
  return response.data;
}

export async function verifyPhone(phone: string, code: string) {
  const response = await defaultApiClient.post<{ phone_verification_token: string }>(
    '/api/v1/store/auth/phone-verification/verify', { phone, code },
  );
  return response.data.phone_verification_token;
}
