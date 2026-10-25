import React from 'react';
import ReactDOM from 'react-dom/client';
import { App } from './App';
import './index.css';
import { BrandingProvider } from '@alfarid/ui/branding';
import { brandingEndpoints } from '@alfarid/api-client';

ReactDOM.createRoot(document.getElementById('root')!).render(
  <React.StrictMode>
    <BrandingProvider loadBranding={brandingEndpoints.get} titleSuffix="لوحة الإدارة"><App /></BrandingProvider>
  </React.StrictMode>
);
