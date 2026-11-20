'use client';

import React, { createContext, useCallback, useContext, useEffect, useState } from 'react';

export type StoreLang = 'en' | 'ar';

const STORAGE_KEY = 'varya_store_lang';

interface StoreLangContextValue {
  lang: StoreLang;
  dir: 'ltr' | 'rtl';
  isAr: boolean;
  setLang: (next: StoreLang) => void;
  toggleLang: () => void;
}

const StoreLangContext = createContext<StoreLangContextValue>({
  lang: 'ar',
  dir: 'rtl',
  isAr: true,
  setLang: () => {},
  toggleLang: () => {},
});

export function StoreLangProvider({ children }: { children: React.ReactNode }) {
  const [lang, setLangState] = useState<StoreLang>('ar');

  useEffect(() => {
    try {
      const saved = localStorage.getItem(STORAGE_KEY);
      if (saved === 'ar' || saved === 'en') {
        setLangState(saved);
      }
    } catch {
      // Ignore storage access errors in restricted browsers.
    }
  }, []);

  const setLang = useCallback((next: StoreLang) => {
    setLangState(next);
    try {
      localStorage.setItem(STORAGE_KEY, next);
    } catch {
      // Ignore storage access errors in restricted browsers.
    }
  }, []);

  const toggleLang = useCallback(() => {
    setLangState((prev) => {
      const next: StoreLang = prev === 'ar' ? 'en' : 'ar';
      try {
        localStorage.setItem(STORAGE_KEY, next);
      } catch {
        // Ignore storage access errors.
      }
      return next;
    });
  }, []);

  const dir: 'ltr' | 'rtl' = lang === 'ar' ? 'rtl' : 'ltr';

  return (
    <StoreLangContext.Provider value={{ lang, dir, isAr: lang === 'ar', setLang, toggleLang }}>
      {children}
    </StoreLangContext.Provider>
  );
}

export function useStoreLang() {
  return useContext(StoreLangContext);
}
