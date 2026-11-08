import React, { useState, useEffect } from 'react';
import { Icon } from '../ui/Icons';
import { egp, fmt, MoneyInput, Api } from '../ui/SharedUI';
import { posMockService } from '../../services/pos-mock.service';
import type { PosShift, Treasury, Branch, CashierUser } from '../../types/pos.types';

interface OpenShiftGateProps {
  onOpened: (shift: PosShift) => void;
}

export function OpenShiftGate({ onOpened }: OpenShiftGateProps) {
  const [treasury, setTreasury] = useState<number>(1);
  const [cash, setCash] = useState<string>('500');
  const [busy, setBusy] = useState<boolean>(false);
  const [treasuries, setTreasuries] = useState<Treasury[]>([]);
  const [branch, setBranch] = useState<Branch | null>(null);
  const [cashier, setCashier] = useState<CashierUser | null>(null);

  useEffect(() => {
    posMockService.getTreasuries().then((list) => {
      setTreasuries(list);
      const free = list.find((t) => t.state === 'free');
      if (free) setTreasury(free.id);
    });
    posMockService.getBranch().then(setBranch);
    posMockService.getCashier().then(setCashier);
  }, []);

  const amount = Number(cash || 0);
  const now = new Date();
  const hr = now.getHours();
  const greet = hr < 12 ? 'صباح الخير' : 'مساء الخير';

  const submit = async () => {
    if (!treasury || busy) return;
    setBusy(true);
    try {
      const opened = await posMockService.openShift(treasury, amount);
      onOpened(opened);
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="gate" data-screen-label="01 Open Shift">
      <div className="gate-card" role="dialog" aria-modal="true" aria-label="فتح الوردية">
        <div className="welcome">
          <div className="row gap-3" style={{ position: 'relative', zIndex: 1 }}>
            <span className="avatar">{cashier?.initials || 'أس'}</span>
            <div className="grow">
              <div style={{ fontSize: 13, opacity: 0.85 }}>{greet}،</div>
              <div className="display" style={{ fontSize: 22, fontWeight: 500, lineHeight: 1.25 }}>
                {cashier?.name || 'أحمد سمير'}
              </div>
            </div>
            <div className="col" style={{ alignItems: 'flex-end', gap: 4 }}>
              <span className="badge" style={{ background: 'rgba(255,255,255,.18)', color: '#fff' }}>
                <Icon n="store" s={13} />
                {branch?.name || 'فرع مدينة نصر'}
              </span>
              <span style={{ fontSize: 12, opacity: 0.85 }} className="num">
                {now.toLocaleDateString('ar-EG', { weekday: 'long', day: 'numeric', month: 'long' })}
              </span>
            </div>
          </div>
          <div
            className="row gap-2"
            style={{
              position: 'relative',
              zIndex: 1,
              marginTop: 16,
              padding: '10px 12px',
              borderRadius: 12,
              background: 'rgba(0,0,0,.14)',
              fontSize: 12.5,
            }}
          >
            <Icon n="lock" s={15} />
            لا توجد وردية مفتوحة باسمك. افتح وردية لبدء البيع.
          </div>
        </div>

        <div style={{ padding: '20px 24px 8px', display: 'flex', flexDirection: 'column', gap: 18 }}>
          <div className="col gap-2">
            <div className="row between">
              <span className="fs-13 fw-5">درج النقدية</span>
              <span className="fs-12 t-3">
                {treasuries.filter((t) => t.state === 'free').length} متاح في الفرع
              </span>
            </div>
            <div className="tres" role="radiogroup">
              {treasuries.map((t) => (
                <button
                  key={t.id}
                  role="radio"
                  aria-checked={treasury === t.id}
                  disabled={t.state === 'busy'}
                  onClick={() => setTreasury(t.id)}
                >
                  <div className="row between">
                    <Icon
                      n="cash"
                      s={18}
                      style={{ color: treasury === t.id ? 'var(--acc)' : 'var(--text-3)' }}
                    />
                    {t.state === 'busy' ? (
                      <span className="badge b-neutral" style={{ height: 20, fontSize: 11 }}>
                        مشغول
                      </span>
                    ) : (
                      <span className="radio-dot" />
                    )}
                  </div>
                  <div className="fs-13 fw-6">{t.name}</div>
                  <div className="fs-12 t-3 truncate" style={{ lineHeight: 1.3 }}>
                    {t.last}
                  </div>
                </button>
              ))}
            </div>
          </div>

          <div className="col gap-2">
            <div className="row between">
              <span className="fs-13 fw-5">العهدة الافتتاحية</span>
              <button className="fs-12 t-3" onClick={() => setCash('')}>
                مسح
              </button>
            </div>
            <MoneyInput value={cash} onChange={setCash} autoFocus onEnter={submit} />
            <div className="denoms">
              {[200, 500, 1000].map((v) => (
                <button key={v} className="denom" onClick={() => setCash(String(amount + v))}>
                  +{fmt(v)}
                  <small>ج</small>
                </button>
              ))}
              <button className="denom ghost" onClick={() => setCash('500')}>
                نفس أمس
              </button>
            </div>
          </div>
        </div>

        <div style={{ padding: '16px 24px 22px' }}>
          <button
            className="pay-btn"
            style={{ margin: 0, width: '100%' }}
            disabled={!treasury || busy}
            onClick={submit}
          >
            <span className="row gap-2">{busy ? 'جارِ فتح الوردية…' : 'فتح الوردية وبدء البيع'}</span>
            <span className="row gap-2">
              <span className="amt num">{egp(amount)}</span>
              <Icon n="arrowLeft" s={18} />
            </span>
          </button>
          <div className="api-block center" style={{ justifyContent: 'center' }}>
            <Api path="/api/v1/pos/shifts/current" /> <Api m="POST" path="/api/v1/pos/shifts/open" />
          </div>
        </div>
      </div>
    </div>
  );
}
