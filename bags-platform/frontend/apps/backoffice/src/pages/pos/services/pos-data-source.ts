import { posMockService } from './pos-mock.service';
import { posApiService } from './pos-api.service';

/**
 * Single switch between the mock and the real API. VITE_POS_SOURCE=mock rolls back instantly.
 * Components import this as `{ pos as posMockService }` so screens never change.
 */
export const pos = import.meta.env.VITE_POS_SOURCE === 'mock' ? posMockService : posApiService;
export { posMockService, posApiService };
