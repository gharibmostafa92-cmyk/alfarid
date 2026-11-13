import { posMockService } from './pos-mock.service';
import { posApiService } from './pos-api.service';

/**
 * Single switch between the mock and the real API. VITE_POS_SOURCE=mock (or the
 * legacy VITE_POS_MOCK=true) rolls back instantly. A production build must never
 * ship with mocks enabled, so fail loudly at module load instead of silently
 * serving fake sales data.
 */
const mockRequested =
  import.meta.env.VITE_POS_SOURCE === 'mock' || import.meta.env.VITE_POS_MOCK === 'true';

if (import.meta.env.PROD && mockRequested) {
  throw new Error('POS mock mode is not allowed in production builds.');
}

export const pos = mockRequested ? posMockService : posApiService;
export { posMockService, posApiService };
/** F-18: true when the terminal runs against the in-memory mock (dev/QA only; PROD builds throw above). */
export const isMockPos = mockRequested;
