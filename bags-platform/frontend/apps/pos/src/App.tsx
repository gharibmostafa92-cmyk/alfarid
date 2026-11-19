import React, { useState } from 'react';
import { authEndpoints, getAuthToken } from '@alfarid/api-client';
import { ChangePasswordForm } from '@alfarid/ui';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { PosApp, ToastHost } from '@alfarid/pos-core';

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 1000 * 60 * 10,
      refetchOnWindowFocus: false,
    },
  },
});

function PosPasswordSettings({ version, token, onVersion }: { version: number; token: string; onVersion: (version: number) => void }) {
  return <ChangePasswordForm minimum={12} staff onSubmit={async ({ current_password, password }, signal) => {
    const result = await authEndpoints.changeStaffPassword('pos', {
      current_password, password, expected_version: version,
    }, token, signal);
    if (getAuthToken() === token) onVersion(result.version);
  }} />;
}

export function App() {
  const [updated, setUpdated] = useState<{ token: string; version: number } | null>(null);
  return (
    <QueryClientProvider client={queryClient}>
      <ToastHost>
        <PosApp renderPasswordSettings={(cashier) => {
          const token = getAuthToken();
          return token && cashier.version != null
            ? <PosPasswordSettings key={token} token={token} version={updated?.token === token ? updated.version : cashier.version}
                onVersion={(version) => setUpdated({ token, version })} />
            : null;
        }} />
      </ToastHost>
    </QueryClientProvider>
  );
}
