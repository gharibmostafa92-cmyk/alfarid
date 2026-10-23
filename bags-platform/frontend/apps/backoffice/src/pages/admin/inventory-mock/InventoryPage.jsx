import React, { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { InventoryReference } from './inventory-reference';
import styles from './inventory-reference.css?inline';

export default function InventoryPage({ can }) {
  const host = useRef(null);
  const [root,setRoot] = useState(null);
  const [dark,setDark] = useState(()=>localStorage.getItem('vortix_theme')==='dark');
  useLayoutEffect(()=>{
    setRoot(host.current.shadowRoot || host.current.attachShadow({mode:'open'}));
    const fonts=document.createElement('link');
    fonts.rel='stylesheet';
    fonts.href='https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=IBM+Plex+Sans+Arabic:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap';
    document.head.append(fonts);
    return ()=>fonts.remove();
  },[]);
  useEffect(()=>{
    const changed=e=>setDark(Boolean(e.detail?.darkMode));
    window.addEventListener('vortix:theme-change',changed);
    return ()=>window.removeEventListener('vortix:theme-change',changed);
  },[]);
  return <div ref={host} data-inventory-mock dir="rtl">{root&&createPortal(<><style>{styles}</style><InventoryReference can={can} isDark={dark}/></>,root)}</div>;
}
