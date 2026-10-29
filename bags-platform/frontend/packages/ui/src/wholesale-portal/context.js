import React, { createContext, useContext } from 'react';

export const AppCtx = createContext(null);
export const useApp = () => {
  const ctx = useContext(AppCtx);
  if (!ctx) {
    return {
      go: () => {},
      route: { screen: 'dashboard', id: null },
      cart: [],
      setCart: () => {},
      addToCart: () => {},
      placeOrder: () => {},
      lastOrder: null,
      notifs: [],
      setNotifs: () => {},
      orders: [],
      setOrders: () => {},
      toast: () => {},
      account: {},
      logout: () => {},
    };
  }
  return ctx;
};
