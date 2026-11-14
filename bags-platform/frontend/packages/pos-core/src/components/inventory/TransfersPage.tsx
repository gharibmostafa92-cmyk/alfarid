import React, { useState, useEffect, useRef } from 'react';
import { Icon } from '../ui/Icons';
import { fmt } from '../ui/SharedUI';
import { ReceiveTab } from './ReceiveTab';
import { RequestTab } from './RequestTab';
import { pos } from '../../services/pos-data-source';
import { isCancel, normalizeError } from '../../lib/pos-error';
import type { Branch, StockTransfer } from '../../types/pos.types';

interface TransfersPageProps {
  toast: (msg: string, tone?: 'ok' | 'bad') => void;
  branch?: Branch | null;
}

// Responses update a service cache shared by all mounts of this page.
let incomingLoad = Promise.resolve();

export function TransfersPage({ toast, branch }: TransfersPageProps) {
  const [tab, setTab] = useState<'receive' | 'request'>('receive');
  const [transfers, setTransfers] = useState<StockTransfer[]>([]);
  const toastRef = useRef(toast);
  const receivedIds = useRef(new Set<number>());

  useEffect(() => { toastRef.current = toast; }, [toast]);

  useEffect(() => {
    let active = true;
    let loading = false;
    let refreshQueued = false;

    const loadTransfers = async () => {
      if (loading) {
        refreshQueued = true;
        return;
      }
      loading = true;
      do {
        refreshQueued = false;
        try {
          const pending = incomingLoad.then(() => active ? pos.getTransfersIn() : undefined);
          incomingLoad = pending.then(() => undefined, () => undefined);
          const list = await pending;
          if (list && active && !refreshQueued) {
            setTransfers(list.filter((transfer) => !receivedIds.current.has(transfer.id)));
          }
        } catch (err) {
          if (active && !refreshQueued && !isCancel(err)) {
            toastRef.current(normalizeError(err).message, 'bad');
          }
        }
      } while (active && refreshQueued);
      loading = false;
    };

    setTransfers([]);
    void loadTransfers();
    const unsubscribe = pos.subscribe(loadTransfers);
    return () => {
      active = false;
      unsubscribe();
    };
  }, [branch?.id]);

  const removeReceived = (id: number) => {
    receivedIds.current.add(id);
    setTransfers((list) => list.filter((transfer) => transfer.id !== id));
  };

  return (
    <div className="page" data-screen-label="09 Transfers">
      <div className="page-in page-enter">
        <div className="page-title">
          <div>
            <h1>التحويلات بين الفروع</h1>
            <p>
              اطلب بضاعة من المخزن أو فرع آخر، واستلم الشحنات الواردة لـ
              {branch?.name || 'الفرع'}.
            </p>
          </div>
        </div>
        <div className="tabs" style={{ marginBottom: 18 }}>
          <button
            aria-selected={tab === 'receive'}
            onClick={() => setTab('receive')}
          >
            <Icon n="truck" s={16} />
            استلام شحنة واردة
            <span className="tab-count">{fmt(transfers.length)}</span>
          </button>
          <button
            aria-selected={tab === 'request'}
            onClick={() => setTab('request')}
          >
            <Icon n="plus" s={16} />
            طلب تحويل وارد للفرع
          </button>
        </div>
        {tab === 'receive' ? (
          <ReceiveTab key={branch?.id} toast={toast} list={transfers} onReceived={removeReceived} />
        ) : (
          <RequestTab key={branch?.id} toast={toast} branch={branch} />
        )}
      </div>
    </div>
  );
}
