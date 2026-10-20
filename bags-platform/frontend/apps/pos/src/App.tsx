import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { CashierScreen } from './pages/CashierScreen';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60 * 10,
      refetchOnWindowFocus: false,
    },
  },
});

export function App() {
  return (
    <QueryClientProvider client={queryClient}>
      <CashierScreen />
    </QueryClientProvider>
  );
}
