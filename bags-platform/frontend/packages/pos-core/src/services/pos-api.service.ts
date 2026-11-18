import { defaultApiClient } from '@alfarid/api-client';
import { PosApiService } from './pos-api.core';

export { PosApiService };
export { configurePosSaleTracing } from './pos-sale-tracing';
export type { PosSaleTrace } from './pos-sale-tracing';
export type { QuoteRequest } from './pos-api.core';
export const posApiService = new PosApiService(defaultApiClient);
