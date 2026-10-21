// Development-only visual fixture. It contains sample data and no authentication bypass.
import React from 'react';
import { createRoot } from 'react-dom/client';
import { FinancialDashboard } from '@alfarid/ui';
import '../src/index.css';

createRoot(document.getElementById('root')!).render(<React.StrictMode><FinancialDashboard /></React.StrictMode>);
