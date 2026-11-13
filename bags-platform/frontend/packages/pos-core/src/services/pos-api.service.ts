import { defaultApiClient } from '@alfarid/api-client';
import { PosApiService } from './pos-api.core';

export { PosApiService };
export type { QuoteRequest } from './pos-api.core';
export const posApiService = new PosApiService(defaultApiClient);
