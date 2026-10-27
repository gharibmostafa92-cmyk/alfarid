/// <reference types="vite/client" />
interface ImportMetaEnv {
  readonly VITE_API_URL?: string;
  readonly VITE_USE_MOCK_OPERATIONS?: 'true' | 'false';
  readonly VITE_OPERATIONS_FALLBACK_ON_ERROR?: 'true' | 'false';
}
interface ImportMeta { readonly env: ImportMetaEnv }
