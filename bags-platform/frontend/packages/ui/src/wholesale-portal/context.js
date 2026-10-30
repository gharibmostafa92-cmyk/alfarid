import React, { createContext, useContext } from 'react';

export const AppCtx = createContext(null);
export const useApp = () => {
  const ctx = useContext(AppCtx);
  if (!ctx) {
    return {
      go: () => {},
      route: { screen: 'dashboard', id: null },
      cart: null,
      cartApi: { state: 'ready', retry: () => {} },
      accountApi: { state: 'ready', retry: () => {} },
      loadCart: () => {},
      addToCart: () => {},
      applyCartResponse: () => {},
      cartTotalQty: 0,
      lastOrder: null,
      setLastOrder: () => {},
      unread: 0,
      setUnread: () => {},
      account: null,
      dash: null,
      refreshAccount: () => {},
      toast: () => {},
      logout: () => {},
    };
  }
  return ctx;
};
