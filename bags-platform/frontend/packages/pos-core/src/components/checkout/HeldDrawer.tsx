import React from 'react';
import { Icon } from '../ui/Icons';
import { egp, fmt, PImg, Empty, Drawer } from '../ui/SharedUI';
import { pos } from '../../services/pos-data-source';
import type { PosHeldSale, Branch } from '../../types/pos.types';

interface HeldDrawerProps {
  open: boolean;
  onClose: () => void;
  held: PosHeldSale[];
  onResume: (h: PosHeldSale) => void;
  onDelete: (h: PosHeldSale) => void;
  cartBusy: boolean;
  branch?: Branch | null;
}

export function HeldDrawer({
  open,
  onClose,
  held,
  onResume,
  onDelete,
  cartBusy,
  branch,
}: HeldDrawerProps) {
  return (
    <Drawer
      open={open}
      onClose={onClose}
      className="held-drawer"
      title="الفواتير المعلقة"
      sub={
        <span className="row gap-2">
          {fmt(held.length)} فاتورة في {branch?.name || 'الفرع'}{' '}
        </span>
      }
    >
      {held.length === 0 ? (
        <Empty
          icon="inbox"
          title="لا توجد فواتير معلقة"
          body="الفواتير التي تعلّقها من شاشة البيع ستظهر هنا لاستكمالها لاحقًا."
        />
      ) : (
        <div className="held-list">
          {cartBusy && (
            <div className="banner b-warn">
              <Icon n="alert" s={16} />
              السلة الحالية بها أصناف — الاسترجاع سيضيف الأصناف إليها.
            </div>
          )}
          {held.map((h) => {
            // F-03: price from the held line itself (unit_price), then catalog;
            // per-line discounts subtract — never catalog-price-only totals.
            const total = h.items.reduce((s, i) => {
              const p = pos.getProductById(i.id);
              return s + (i.unit_price ?? p?.price ?? 0) * i.qty - (i.discount_amount ?? 0);
            }, 0);
            const pcs = h.items.reduce((s, i) => s + i.qty, 0);
            const extraCount = h.items.length > 3 ? h.items.length - 3 : 0;

            return (
              <div key={h.ref} className="held-card" data-screen-label="06 Held Sale">
                <div className="held-card-head">
                  <div className="held-card-id">
                    <b className="mono held-card-ref">{h.ref}</b>
                    {h.mins > 60 && (
                      <span className="badge b-warn held-card-age">
                        منذ أكثر من ساعة
                      </span>
                    )}
                  </div>
                  <span className="price held-card-price">
                    {egp(total)}
                    <small>ج.م</small>
                  </span>
                </div>

                <div className="held-card-body">
                  <div className="stack-thumbs">
                    {h.items.slice(0, 3).map((i) => (
                      <PImg
                        key={i.id}
                        p={pos.getProductById(i.id) ?? { cat: 'hand', h: 240 }}
                        size="sm"
                      />
                    ))}
                    {extraCount > 0 && (
                      <span className="stack-thumbs-more num">+{fmt(extraCount)}</span>
                    )}
                  </div>
                  <div className="held-card-meta">
                    <div className="held-card-customer">
                      <Icon n="user" s={13} />
                      <span className="truncate">{h.customer || 'بدون عميل'}</span>
                    </div>
                    <div className="held-card-sub">
                      <span className="row gap-1">
                        <Icon n="history" s={12} />
                        {h.at}
                      </span>
                      <span className="dot-sep" />
                      <span>{fmt(pcs)} قطعة</span>
                    </div>
                  </div>
                </div>

                {h.note && (
                  <div className="held-card-note">
                    <Icon n="edit" s={14} />
                    <span>{h.note}</span>
                  </div>
                )}

                <div className="held-card-actions">
                  <button className="btn btn-primary grow" onClick={() => onResume(h)}>
                    <Icon n="arrowUpLeft" s={15} />
                    استرجاع الفاتورة
                  </button>
                  <button
                    className="btn btn-ghost held-card-del"
                    onClick={() => onDelete(h)}
                    aria-label={`حذف الفاتورة المعلقة ${h.ref}`}
                  >
                    <Icon n="trash" s={15} />
                    <span>حذف</span>
                  </button>
                </div>
              </div>
            );
          })}
        </div>
      )}
    </Drawer>
  );
}
