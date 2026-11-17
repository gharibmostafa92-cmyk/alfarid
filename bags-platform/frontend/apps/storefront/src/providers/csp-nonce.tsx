'use client';

import { createContext, useContext, type ReactNode } from 'react';

const CspNonceContext = createContext('');

export function NonceProvider({ nonce = '', children }: { nonce?: string; children: ReactNode }) {
  return <CspNonceContext.Provider value={nonce}>{children}</CspNonceContext.Provider>;
}

export function useCspNonce(): string {
  return useContext(CspNonceContext);
}
