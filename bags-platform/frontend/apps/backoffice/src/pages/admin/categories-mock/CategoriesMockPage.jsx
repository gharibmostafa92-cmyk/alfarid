import React, { useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { CategoriesReferencePage, ToastHost } from './categories-reference';
import styles from './categories-reference.css?inline';
import { CategoriesContext } from './categories-context';

// Keep the reference's tokens, Tailwind reset and component styles inside this page.
// Vortix owns navigation; only the supplied categories design is rendered here.
export default function CategoriesMockPage({ can, forceState = null, embedded = false }) {
  const host = useRef(null);
  const [root, setRoot] = useState(null);
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

  return (
    <div ref={host} data-categories-mock="" dir="rtl">
      {root && createPortal(
        <CategoriesContext.Provider value={context}>
          <style>{styles}</style>
          <div className="categories-surface theme-light" data-embedded={embedded || undefined} dir="rtl" lang="ar">
            <CategoriesReferencePage />
            <ToastHost />
          </div>
        </CategoriesContext.Provider>,
        root,
      )}
    </div>
  );
}
