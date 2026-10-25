import React from 'react';
import ReactDOM from 'react-dom/client';
import { App } from './App';
import './index.css';
import { BrandingProvider } from '@alfarid/ui/branding';
import { brandingEndpoints, defaultApiClient, resolveServiceUrl } from '@alfarid/api-client';

defaultApiClient.defaults.baseURL = resolveServiceUrl(import.meta.env.VITE_API_URL ?? 'http://localhost:8000');

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <BrandingProvider loadBranding={brandingEndpoints.get} titleSuffix="نقاط البيع"><App /></BrandingProvider>
  </React.StrictMode>
);
