import React from 'react';

export const fmt = (n, d = 2) => Number(n ?? 0).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
export const Money = ({ v, strong, cls = '' }) => (
  <span className={`num ${cls}`} style={strong ? { fontWeight: 600, color: 'var(--ink)' } : null}>
    {fmt(v)} <small style={{ fontSize: '0.78em', color: 'var(--soft)', fontWeight: 500 }}>EGP</small>
  </span>
);
export const D = (s) => <span className="num">{s}</span>;
