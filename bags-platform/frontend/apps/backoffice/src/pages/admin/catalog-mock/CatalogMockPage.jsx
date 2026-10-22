import React, { useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { CatalogReference } from './catalog-reference';
import styles from './catalog-reference.css?inline';
export default function CatalogMockPage({ can, page }) {
 const host = useRef(null); const [root,setRoot] = useState(null);
 useLayoutEffect(()=>{setRoot(host.current.shadowRoot || host.current.attachShadow({mode:'open'})); const fonts=document.createElement('link');fonts.rel='stylesheet';fonts.href='https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Readex+Pro:wght@400;500;600;700&display=swap';document.head.append(fonts);return()=>fonts.remove();},[]);
 return <div ref={host} data-catalog-mock={page} dir="rtl">{root && createPortal(<><style>{styles}</style><CatalogReference can={can} page={page}/></>,root)}</div>;
}
