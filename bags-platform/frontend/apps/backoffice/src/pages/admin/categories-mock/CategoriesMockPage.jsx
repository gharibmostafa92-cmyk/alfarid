import React, { useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { CategoriesReferencePage, ToastHost } from './categories-reference';
import styles from './categories-reference.css?inline';
import { CategoriesContext } from './categories-context';

// Keep the reference's tokens, Tailwind reset and component styles inside this page.
// Vortix owns navigation; only the supplied categories design is rendered here.
export default function CategoriesMockPage({ can, forceState = null, embedded = false, darkMode = undefined, theme = undefined }) {
  const host = useRef(null);
  const [root, setRoot] = useState(null);
  const [detectedDark, setDetectedDark] = useState(() => {
    if (typeof darkMode === 'boolean') return darkMode;
    if (typeof theme === 'string') return theme === 'dark';
    try {
      return localStorage.getItem('vortix_theme') === 'dark';
    } catch {
      return false;
    }
  });

  const isDark = typeof darkMode === 'boolean' ? darkMode : (theme ? theme === 'dark' : detectedDark);

  const perms = useMemo(() => ({
    view:   can('categories.view'),
    create: can('categories.create'),
    update: can('categories.update'),   // يشمل الترتيب والتفعيل والتعطيل
    delete: can('categories.delete'),
  }), [can]);
  const context = useMemo(() => ({
    user: { can },
    perms,
    screenState: forceState || 'ready',
  }), [can, perms, forceState]);

  useLayoutEffect(() => {
    setRoot(host.current.shadowRoot || host.current.attachShadow({ mode: 'open' }));
    const fonts = document.createElement('link');
    fonts.rel = 'stylesheet';
    fonts.href = 'https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Readex+Pro:wght@400;500;600;700&display=swap';
    document.head.append(fonts);
    return () => fonts.remove();
  }, []);

  useEffect(() => {
    if (host.current) {
      host.current.setAttribute('data-theme', isDark ? 'dark' : 'light');
    }
  }, [isDark]);

  useEffect(() => {
    const handleCustom = (e) => {
      if (typeof e.detail?.darkMode === 'boolean') {
        setDetectedDark(e.detail.darkMode);
      }
    };
    window.addEventListener('vortix:theme-change', handleCustom);

    const checkParent = () => {
      const rootNode = host.current?.getRootNode();
      const parentViewport = host.current?.closest('#app-viewport');
      const target = parentViewport || (rootNode?.host?.querySelector?.('#app-viewport') || rootNode?.host) || document.querySelector('#app-viewport') || document.querySelector('[data-vortix-host]');
      if (!target) return null;
      const dark = target.classList?.contains('theme-dark') || target.getAttribute?.('data-theme') === 'dark';
      setDetectedDark(dark);
      return target;
    };

    const target = checkParent();
    let observer;
    if (target) {
      observer = new MutationObserver(() => checkParent());
      observer.observe(target, { attributes: true, attributeFilter: ['class', 'data-theme'] });
    }

    return () => {
      window.removeEventListener('vortix:theme-change', handleCustom);
      observer?.disconnect();
    };
  }, []);

  return (
    <div ref={host} data-categories-mock="" data-theme={isDark ? 'dark' : 'light'} dir="rtl">
      {root && createPortal(
        <CategoriesContext.Provider value={context}>
          <style>{styles}</style>
          <div className={`categories-surface ${isDark ? 'theme-dark' : 'theme-light'}`} data-embedded={embedded || undefined} dir="rtl" lang="ar">
            <CategoriesReferencePage />
            <ToastHost />
          </div>
        </CategoriesContext.Provider>,
        root,
      )}
    </div>
  );
}
