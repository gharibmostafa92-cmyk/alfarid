import React, { useState, useEffect } from 'react';
import { Icon } from '../ui/Icons';
import { fmt } from '../ui/SharedUI';
import { ReceiveTab } from './ReceiveTab';
import { RequestTab } from './RequestTab';
import { posMockService } from '../../services/pos-mock.service';
import type { Branch, StockTransfer } from '../../types/pos.types';

interface TransfersPageProps {
  toast: (msg: string, tone?: 'ok' | 'bad') => void;
  branch?: Branch | null;
}

export function TransfersPage({ toast, branch }: TransfersPageProps) {
  const [tab, setTab] = useState<'receive' | 'request'>('receive');
  const [transfers, setTransfers] = useState<StockTransfer[]>([]);

  const loadTransfers = () => {
    posMockService.getTransfersIn().then(setTransfers);
  };

  useEffect(() => {
    loadTransfers();
    const unsub = posMockService.subscribe(loadTransfers);
    return unsub;
  }, []);

  return (
    <div className="page" data-screen-label="09 Transfers">
      <div className="page-in page-enter">
        <div className="page-title">
          <div>
            <h1>التحويلات بين الفروع</h1>
            <p>
              اطلب بضاعة من المخزن أو فرع آخر، واستلم الشحنات الواردة لـ
              {branch?.name || 'فرع مدينة نصر'}.
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
          <ReceiveTab toast={toast} />
        ) : (
          <RequestTab toast={toast} branch={branch} />
        )}
      </div>
    </div>
  );
}
