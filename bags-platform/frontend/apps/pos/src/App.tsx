import React from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { PosApp } from './components/PosApp';
import { ToastHost } from './components/ui/SharedUI';

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
      <ToastHost>
        <PosApp />
      </ToastHost>
    </QueryClientProvider>
  );
}
