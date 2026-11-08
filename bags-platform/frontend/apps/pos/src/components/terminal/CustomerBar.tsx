import React, { useState, useRef, useEffect } from 'react';
import { Icon } from '../ui/Icons';
import { fmt, cx, toAsciiNum, Field, Seg, Modal, useOutside } from '../ui/SharedUI';
import { posMockService } from '../../services/pos-mock.service';
import type { Customer } from '../../types/pos.types';

interface CustomerBarProps {
  customer: Customer | null;
  setCustomer: (c: Customer | null) => void;
  toast: (msg: string, tone?: 'ok' | 'bad') => void;
}

export function CustomerBar({ customer, setCustomer, toast }: CustomerBarProps) {
  const [q, setQ] = useState('');
  const [open, setOpen] = useState(false);
  const [quick, setQuick] = useState(false);
  const [nw, setNw] = useState<{ name: string; phone: string; tier: 'retail' | 'wholesale' }>({
    name: '',
    phone: '',
    tier: 'retail',
  });
  const [customers, setCustomers] = useState<Customer[]>([]);
  const ref = useRef<HTMLDivElement>(null);
  useOutside(ref, () => setOpen(false));

  useEffect(() => {
    posMockService.getCustomers().then(setCustomers);
  }, []);

  const res = customers.filter(
    (c) => !q || c.phone.includes(q) || c.name.includes(q)
  );

  const handleCreateCustomer = async () => {
    if (!nw.name || nw.phone.length < 10) return;
    const created = await posMockService.createQuickCustomer({
      name: nw.name,
      phone: nw.phone,
      tier: nw.tier,
    });
    setCustomer(created);
    setQuick(false);
    setNw({ name: '', phone: '', tier: 'retail' });
    toast('تم تسجيل العميل وربطه بالفاتورة');
    posMockService.getCustomers().then(setCustomers);
  };

  if (customer) {
    return (
      <div className="cust">
        <div className="cust-chip">
          <span
            className="avatar"
            style={{ background: 'var(--acc-soft)', color: 'var(--acc)' }}
          >
            {customer.name.slice(0, 1)}
          </span>
          <div className="grow">
            <div className="fs-13 fw-6 row gap-2">
              {customer.name}
              {customer.tier === 'wholesale' ? (
                <span className="badge b-info" style={{ height: 20, fontSize: 11 }}>
                  أسعار جملة
                </span>
              ) : (
                <span className="badge b-neutral" style={{ height: 20, fontSize: 11 }}>
                  تجزئة
                </span>
              )}
            </div>
            <div className="fs-12 t-3 row gap-1">
              <span className="num ltr">{customer.phone}</span>
              <span className="dot-sep" />
              <Icon n="star" s={12} />
              {fmt(customer.points)} نقطة
            </div>
          </div>
          <button
            className="icon-btn sm"
            onClick={() => setCustomer(null)}
            aria-label="إزالة العميل"
          >
            <Icon n="x" s={15} />
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="cust" ref={ref}>
      <div className="row gap-2">
        <div className="input-group grow">
          <Icon n="phone" s={15} />
          <input
            className="input"
            style={{ height: 40 }}
            value={q}
            onChange={(e) => {
              setQ(e.target.value);
              setOpen(true);
            }}
            onFocus={() => setOpen(true)}
            placeholder="عميل: رقم الهاتف أو الاسم"
          />
        </div>
        <button
          className="btn btn-secondary"
          style={{ height: 40, padding: '0 12px' }}
          onClick={() => setQuick(true)}
          title="تسجيل عميل سريع"
        >
          <Icon n="userPlus" s={16} />
          جديد
        </button>
      </div>
      {open && (
        <div className="menu" style={{ top: 'calc(100% - 6px)', insetInline: 14 }}>
          {res.map((c) => (
            <button
              key={c.id}
              onClick={() => {
                setCustomer(c);
                setOpen(false);
                setQ('');
              }}
            >
              <span className="avatar" style={{ width: 26, height: 26, fontSize: 11 }}>
                {c.name.slice(0, 1)}
              </span>
              <span className="grow">{c.name}</span>
              <span className="num ltr t-3 fs-12">{c.phone}</span>
            </button>
          ))}
          {res.length === 0 && (
            <button
              onClick={() => {
                setNw({ ...nw, phone: q });
                setQuick(true);
                setOpen(false);
              }}
            >
              <Icon n="userPlus" s={16} />
              تسجيل «{q}» كعميل جديد
            </button>
          )}
        </div>
      )}
      <Modal
        open={quick}
        onClose={() => setQuick(false)}
        title="تسجيل عميل سريع"
        width={420}
        footer={
          <>
            <button className="btn btn-secondary" onClick={() => setQuick(false)}>
              إلغاء
            </button>
            <button
              className="btn btn-primary"
              disabled={!nw.name || nw.phone.length < 10}
              onClick={handleCreateCustomer}
            >
              حفظ وربط بالفاتورة
            </button>
          </>
        }
      >
        <div className="col gap-4">
          <Field label="الاسم" req>
            <input
              className="input"
              autoFocus
              value={nw.name}
              onChange={(e) => setNw({ ...nw, name: e.target.value })}
              placeholder="اسم العميل"
            />
          </Field>
          <Field label="رقم الهاتف" req>
            <input
              className="input ltr num"
              style={{ textAlign: 'right' }}
              value={nw.phone}
              onChange={(e) => setNw({ ...nw, phone: toAsciiNum(e.target.value) })}
              placeholder="01xxxxxxxxx"
            />
          </Field>
          <Field label="نوع التسعير">
            <Seg
              value={nw.tier}
              onChange={(v) => setNw({ ...nw, tier: v })}
              options={[
                { value: 'retail', label: 'تجزئة + نقاط ولاء' },
                { value: 'wholesale', label: 'أسعار جملة' },
              ]}
            />
          </Field>
        </div>
      </Modal>
    </div>
  );
}
