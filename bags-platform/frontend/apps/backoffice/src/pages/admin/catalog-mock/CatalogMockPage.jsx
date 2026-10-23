import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { CatalogReference } from './catalog-reference';
import styles from './catalog-reference.css?inline';

export default function CatalogMockPage({ can, page, darkMode = undefined, theme = undefined }) {
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
    <div ref={host} data-catalog-mock={page} data-theme={isDark ? 'dark' : 'light'} dir="rtl">
      {root && createPortal(
        <>
          <style>{styles}</style>
          <CatalogReference can={can} page={page} isDark={isDark} />
        </>,
        root
      )}
    </div>
  );
}
