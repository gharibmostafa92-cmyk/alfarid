import React, { useState, createContext, useContext, Fragment } from 'react';

/* Shared primitives — mirror @alfarid/ui + sophie.css patterns. */

export const Badge = ({ map, k, lg }) => {
  const [label, v] = map[k] || [k, 'default'];
  return <span className={`badge ${v}${lg ? ' lg' : ''}`}>{label}</span>;
};

export const PageHead = ({ en, ar, crumb, children }) => (
  <div className="wp-page-top">
    <div>
      {crumb && <div className="wp-crumb">{crumb}</div>}
      <h1 className="wp-giant">{en}</h1>
      {ar && <p className="wp-page-ar">{ar}</p>}
    </div>
    {children && <div className="wp-actions">{children}</div>}
  </div>
);

export const Crumb = ({ items }) => (
  <>{items.map((it, i) => (
    <React.Fragment key={i}>
      {i > 0 && <i className="fa-solid fa-chevron-left" style={{ fontSize: 9 }} />}
      {it.go ? <button onClick={it.go}>{it.label}</button> : <span className={it.mono ? 'num' : ''}>{it.label}</span>}
    </React.Fragment>
  ))}</>
);

export const Panel = ({ title, en, action, children, body = true, style }) => (
  <section className="panel" style={style}>
    {(title || action) && (
      <div className="panel-head">
        <div className="row" style={{ gap: 10, alignItems: 'baseline' }}>
          {title && <h2 className="panel-title">{title}</h2>}
          {en && <span className="panel-en">{en}</span>}
        </div>
        {action}
      </div>
    )}
    {body ? <div className="panel-body">{children}</div> : children}
  </section>
);

/* Loading / empty / error */
export const SkeletonRows = ({ rows = 5, cols = 5 }) => (
  <div style={{ padding: '18px 24px' }} aria-busy="true" aria-label="جارٍ التحميل">
    {Array.from({ length: rows }).map((_, r) => (
      <div key={r} style={{ display: 'grid', gridTemplateColumns: `repeat(${cols}, 1fr)`, gap: 18, padding: '14px 0', borderBottom: '1px solid var(--line-2)' }}>
        {Array.from({ length: cols }).map((_, c) => <div key={c} className="sk" style={{ height: 12, width: `${55 + ((r * 7 + c * 13) % 40)}%` }} />)}
      </div>
    ))}
  </div>
);
export const Empty = ({ icon = 'fa-inbox', title, desc, action }) => (
  <div className="state">
    <div className="state-icon"><i className={`fa-solid ${icon}`} /></div>
    <p className="state-title">{title}</p>
    {desc && <p className="state-desc">{desc}</p>}
    {action}
  </div>
);
export const ErrorState = ({ onRetry, title = 'تعذر تحميل البيانات', desc = 'حصلت مشكلة في الاتصال بالخادم. تأكد من الاتصال وحاول مرة أخرى.' }) => (
  <div className="state" role="alert">
    <div className="state-icon is-err"><i className="fa-solid fa-triangle-exclamation" /></div>
    <p className="state-title">{title}</p>
    <p className="state-desc">{desc}</p>
    <button className="btn-pill is-dark" onClick={onRetry}><i className="fa-solid fa-rotate-right" /> إعادة المحاولة</button>
  </div>
);

/* Async wrapper — global "data state" tweak drives it */
export const DataStateCtx = React.createContext({ state: 'ready', retry: () => {} });
export const Async = ({ children, empty, rows, cols, isEmpty }) => {
  const { state, retry } = React.useContext(DataStateCtx);
  if (state === 'loading') return <SkeletonRows rows={rows} cols={cols} />;
  if (state === 'error') return <ErrorState onRetry={retry} />;
  if (state === 'empty' || isEmpty) return empty || <Empty title="لا توجد بيانات" />;
  return children;
};

export const Pager = ({ page = 1, pages = 3, total, per = 15, onPage = () => {} }) => (
  <div className="pager">
    <span>عرض <span className="num">{(page - 1) * per + 1}–{Math.min(page * per, total)}</span> من <span className="num">{total}</span></span>
    <div className="pager-btns">
      <button disabled={page === 1} onClick={() => onPage(page - 1)} aria-label="السابق"><i className="fa-solid fa-chevron-right" /></button>
      {Array.from({ length: pages }).map((_, i) => <button key={i} className={page === i + 1 ? 'is-on' : ''} onClick={() => onPage(i + 1)}>{i + 1}</button>)}
      <button disabled={page === pages} onClick={() => onPage(page + 1)} aria-label="التالي"><i className="fa-solid fa-chevron-left" /></button>
    </div>
  </div>
);

export const Field = ({ label, req, opt, error, hint, span, children, counter }) => (
  <div className={span ? 'span-2' : ''}>
    <div className="row between">
      <label className="f-label">{label}{req && <span className="f-req">*</span>}{opt && <span className="f-opt">(اختياري)</span>}</label>
      {counter}
    </div>
    {children}
    {error && <p className="f-error" role="alert"><i className="fa-solid fa-circle-exclamation" />{error}</p>}
    {!error && hint && <p className="f-hint">{hint}</p>}
  </div>
);

export const Modal = ({ title, onClose, children, foot, wide, icon }) => {
  useEffect(() => {
    const k = (e) => e.key === 'Escape' && onClose();
    window.addEventListener('keydown', k);
    return () => window.removeEventListener('keydown', k);
  }, [onClose]);
  return (
    <div className="modal-bg" onMouseDown={(e) => e.target === e.currentTarget && onClose()}>
      <div className={`modal${wide ? ' wide' : ''}`} role="dialog" aria-modal="true">
        <div className="modal-head">
          <div className="row" style={{ gap: 14 }}>
            {icon}
            <h2 className="modal-title">{title}</h2>
          </div>
          <button className="modal-x" onClick={onClose} aria-label="إغلاق"><i className="fa-solid fa-xmark" /></button>
        </div>
        <div className="modal-body">{children}</div>
        {foot && <div className="modal-foot">{foot}</div>}
      </div>
    </div>
  );
};

export const Banner = ({ kind = 'info', icon, children, action }) => (
  <div className={`banner is-${kind}`} role={kind === 'error' ? 'alert' : 'status'}>
    <i className={`fa-solid ${icon || { info: 'fa-circle-info', error: 'fa-circle-exclamation', warn: 'fa-triangle-exclamation', ok: 'fa-circle-check' }[kind]}`} />
    <div className="banner-body">{children}</div>
    {action}
  </div>
);

export const Qty = ({ value, onChange, min = 0 }) => (
  <div className="qty ltr">
    <button type="button" onClick={() => onChange(Math.max(min, value - 1))} aria-label="إنقاص"><i className="fa-solid fa-minus" /></button>
    <input value={value} inputMode="numeric" onChange={(e) => onChange(Math.max(min, parseInt(e.target.value.replace(/\D/g, '') || '0', 10)))} aria-label="الكمية" />
    <button type="button" onClick={() => onChange(value + 1)} aria-label="زيادة"><i className="fa-solid fa-plus" /></button>
  </div>
);

export const Thumb = ({ sm }) => <div className={`thumb${sm ? ' sm' : ''}`} aria-hidden="true"><i className="fa-solid fa-bag-shopping" /></div>;

export const Seg = ({ options, value, onChange }) => (
  <div className="seg" role="tablist">
    {options.map((o) => (
      <button key={o.value} role="tab" aria-selected={value === o.value} className={value === o.value ? 'is-on' : ''} onClick={() => onChange(o.value)}>
        {o.icon && <i className={`fa-solid ${o.icon}`} />}{o.label}{o.n != null && <span className="seg-n">{o.n}</span>}
      </button>
    ))}
  </div>
);



