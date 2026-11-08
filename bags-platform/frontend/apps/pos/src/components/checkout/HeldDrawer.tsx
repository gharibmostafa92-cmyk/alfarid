import React from 'react';
import { Icon } from '../ui/Icons';
import { egp, fmt, PImg, Api, Empty, Drawer } from '../ui/SharedUI';
import { posMockService } from '../../services/pos-mock.service';
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
      title="الفواتير المعلقة"
      sub={
        <span className="row gap-2">
          {fmt(held.length)} فاتورة في {branch?.name || 'فرع مدينة نصر'}{' '}
          <Api path="/pos/sales/held" />
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
        <div className="col gap-3">
          {cartBusy && (
            <div className="banner b-warn">
              <Icon n="alert" s={16} />
              السلة الحالية بها أصناف — الاسترجاع سيضيف الأصناف إليها.
            </div>
          )}
          {held.map((h) => {
            const total = h.items.reduce((s, i) => {
              const p = posMockService.getProductById(i.id);
              return s + (p?.price || 0) * i.qty;
            }, 0);
            const pcs = h.items.reduce((s, i) => s + i.qty, 0);

            return (
              <div key={h.ref} className="held-card" data-screen-label="06 Held Sale">
                <div className="row gap-3">
                  <div className="stack-thumbs">
                    {h.items.slice(0, 3).map((i) => (
                      <PImg
                        key={i.id}
                        p={posMockService.getProductById(i.id)}
                        size="sm"
                      />
                    ))}
                  </div>
                  <div className="grow">
                    <div className="row gap-2">
                      <b className="mono" style={{ fontSize: 13 }}>
                        {h.ref}
                      </b>
                      {h.mins > 60 && (
                        <span className="badge b-warn" style={{ height: 20, fontSize: 11 }}>
                          منذ أكثر من ساعة
                        </span>
                      )}
                    </div>
                    <div className="fs-12 t-3 row gap-1">
                      <Icon n="history" s={12} />
                      {h.at}
                      <span className="dot-sep" />
                      {fmt(pcs)} قطعة
                      <span className="dot-sep" />
                      {h.customer || 'بدون عميل'}
                    </div>
                  </div>
                  <span className="price fs-20">
                    {egp(total)}
                    <small>ج</small>
                  </span>
                </div>
                {h.note && (
                  <div
                    className="fs-13 t-2 row gap-2"
                    style={{
                      padding: '8px 10px',
                      borderRadius: 10,
                      background: 'var(--bg-sunken)',
                    }}
                  >
                    <Icon n="edit" s={14} />
                    {h.note}
                  </div>
                )}
                <div className="row gap-2">
                  <button className="btn btn-primary grow" onClick={() => onResume(h)}>
                    <Icon n="arrowUpLeft" s={15} />
                    استرجاع الفاتورة
                  </button>
                  <button className="btn btn-ghost" onClick={() => onDelete(h)}>
                    <Icon n="trash" s={15} />
                    حذف
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
