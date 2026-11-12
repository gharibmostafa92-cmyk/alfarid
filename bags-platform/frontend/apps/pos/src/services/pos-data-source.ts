import { posMockService } from './pos-mock.service';

/**
 * Single switch between the mock and the real API. VITE_POS_SOURCE=mock (or the
 * legacy VITE_POS_MOCK=true) rolls back instantly. A production build must never
 * ship with mocks enabled, so fail loudly at module load instead of silently
 * serving fake sales data.
 */
const mockRequested =
  import.meta.env.VITE_POS_SOURCE === 'mock' || import.meta.env.VITE_POS_MOCK === 'true';

if (import.meta.env.PROD && !mockRequested) {
  throw new Error(
    'POS mock data source is not allowed in production builds. ' +
      'Wire the real pos API service or set VITE_POS_SOURCE=mock only for local development.',
  );
}

export const pos = posMockService;
export { posMockService };
