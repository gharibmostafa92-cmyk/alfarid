/** When true, uses local mock data instead of live backend APIs. */
export const USE_MOCK = import.meta.env.VITE_USE_MOCK_OPERATIONS === 'true';
/** When true, displays local mock data on network/5xx failures in dev; forbidden in production. */
export const FALLBACK_ON_ERROR =
  import.meta.env.VITE_OPERATIONS_FALLBACK_ON_ERROR === 'true' && !import.meta.env.PROD;
