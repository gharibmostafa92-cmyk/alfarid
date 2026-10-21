import axios, { AxiosInstance, InternalAxiosRequestConfig } from 'axios';

declare const process: any;

export interface ApiClientConfig {
  baseURL?: string;
  withCredentials?: boolean;
  getToken?: () => string | null | undefined;
  onUnauthorized?: () => void;
}

let activeToken: string | null = null;
let unauthorizedHandler: (() => void) | null = null;

export function setAuthToken(token: string | null) {
  activeToken = token;
}

export function getAuthToken(): string | null {
  return activeToken;
}

export function setUnauthorizedHandler(handler: (() => void) | null) {
  unauthorizedHandler = handler;
}

export function createApiClient(config: ApiClientConfig = {}): AxiosInstance {
  const envBaseUrl = typeof process !== 'undefined' && process.env 
    ? (process.env.NEXT_PUBLIC_API_URL || process.env.VITE_API_URL) 
    : undefined;

  const instance = axios.create({
    baseURL: config.baseURL || envBaseUrl || 'http://localhost:8000',
    timeout: 30000,
    withCredentials: config.withCredentials ?? false,
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
    },
  });

  instance.interceptors.request.use((reqConfig: InternalAxiosRequestConfig) => {
    const token = config.getToken ? config.getToken() : activeToken;
    if (token && reqConfig.headers) {
      reqConfig.headers.Authorization = `Bearer ${token}`;
    }
    return reqConfig;
  });

  instance.interceptors.response.use(
    (response) => response,
    (error) => {
      const isSignInRequest = ['/api/v1/auth/login', '/api/v1/auth/handoff/exchange']
        .some((path) => error.config?.url?.split('?')[0] === path);
      if (error.response?.status === 401 && !isSignInRequest) {
        if (config.onUnauthorized) {
          config.onUnauthorized();
        } else if (unauthorizedHandler) {
          unauthorizedHandler();
        }
      }
      return Promise.reject(error);
    }
  );

  return instance;
}

export const defaultApiClient = createApiClient();
