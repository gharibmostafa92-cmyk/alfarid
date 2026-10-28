import { useCallback, useEffect, useRef, useState } from 'react';
import { notificationsApi, parseApiError, isAxiosCancel, type NotificationApi } from '@alfarid/api-client';
import { NOTIFS, type NotificationItem } from '../data';
import { USE_MOCK, FALLBACK_ON_ERROR } from './source';

export function notificationTarget(n: NotificationItem): { m: string; id?: number; sub?: string } | null {
  const d = n.data ?? {};
  if (d.return_request_id) return { m: 'returns', id: Number(d.return_request_id) };
  if (d.order_id) return { m: 'orders', id: Number(d.order_id) };
  if (d.purchase_order_id) return { m: 'purchasing', sub: 'po', id: Number(d.purchase_order_id) };
  if (d.quotation_id) return { m: 'wholesale', sub: 'quotes' };
  if (d.payment_notice_id) return { m: 'wholesale', sub: 'notices' };
  if (d.pos_shift_id) return { m: 'shifts' };
  return USE_MOCK ? d.target ?? null : null;
}
const toItem = (n: NotificationApi): NotificationItem => ({ ...n, created_at: n.created_at ?? '' });
const POLL_MS = 60_000;

export function useNotifications(onError?: (message: string) => void) {
  const [items, setItems] = useState<NotificationItem[]>(USE_MOCK ? NOTIFS : []);
  const itemsRef = useRef(items); itemsRef.current = items;
  const [unread, setUnread] = useState(USE_MOCK ? NOTIFS.filter(n => !n.read_at).length : 0);
  const [page, setPage] = useState(1);
  const [lastPage, setLastPage] = useState(1);
  const [loading, setLoading] = useState(!USE_MOCK);
  const [error, setError] = useState<string | null>(null);
  const [usingMock, setUsingMock] = useState(USE_MOCK);
  const onErrorRef = useRef(onError); onErrorRef.current = onError;
  const ctrlRef = useRef<AbortController | null>(null);
  const pending = useRef(new Set<string>());
  const mounted = useRef(true);
  const bulkPending = useRef(false);
  const unreadRef = useRef(unread); unreadRef.current = unread;
  const refreshCtrl = useRef<AbortController | null>(null);

  const load = useCallback(async (p = 1) => {
    if (USE_MOCK) return;
    ctrlRef.current?.abort();
    const ctrl = new AbortController(); ctrlRef.current = ctrl;
    setLoading(true); setError(null);
    try {
      const r = await notificationsApi.list(p, ctrl.signal);
      if (ctrl.signal.aborted) return;
      setItems(prev => {
        const incoming = r.data.map(toItem).map(n => pending.current.has(n.id) ? { ...n, read_at: n.read_at ?? new Date().toISOString() } : n);
        return [...new Map((p === 1 ? incoming : [...prev, ...incoming]).map(n => [n.id, n])).values()];
      });
      setUnread(r.meta.unread_count); setPage(r.meta.current_page); setLastPage(r.meta.last_page); setUsingMock(false);
    } catch (e) {
      if (ctrl.signal.aborted || isAxiosCancel(e)) return;
      const parsed = parseApiError(e);
      setError(parsed.message); onErrorRef.current?.(parsed.message);
      if (FALLBACK_ON_ERROR && (parsed.status === null || parsed.status >= 500)) {
        setItems(NOTIFS); setUnread(NOTIFS.filter(n => !n.read_at).length); setUsingMock(true); setPage(1); setLastPage(1);
      } else { setItems([]); setUnread(0); setUsingMock(false); }
    } finally { if (!ctrl.signal.aborted) setLoading(false); }
  }, []);

  const refreshQuiet = useCallback(async () => {
    if (USE_MOCK || bulkPending.current || refreshCtrl.current) return;
    const ctrl = new AbortController();
    refreshCtrl.current = ctrl;
    try {
      const r = await notificationsApi.list(1, ctrl.signal);
      if (ctrl.signal.aborted || !mounted.current) return;
      setItems(prev => {
        const incoming = r.data.map(toItem).map(n => pending.current.has(n.id) ? { ...n, read_at: n.read_at ?? new Date().toISOString() } : n);
        const incomingIds = new Set(incoming.map(n => n.id));
        return [...incoming, ...prev.filter(n => !incomingIds.has(n.id))];
      });
      setUnread(r.meta.unread_count); setLastPage(r.meta.last_page); setUsingMock(false);
    } catch {
      // Polling failures leave the last successfully loaded list visible.
    } finally { if (refreshCtrl.current === ctrl) refreshCtrl.current = null; }
  }, []);

  useEffect(() => {
    mounted.current = true; void load(1);
    const poll = () => { if (document.visibilityState === 'visible' && pending.current.size === 0 && !bulkPending.current) void refreshQuiet(); };
    const timer = setInterval(poll, POLL_MS);
    document.addEventListener('visibilitychange', poll);
    return () => { mounted.current = false; clearInterval(timer); document.removeEventListener('visibilitychange', poll); ctrlRef.current?.abort(); refreshCtrl.current?.abort(); };
  }, [load, refreshQuiet]);

  const markRead = useCallback(async (id: string) => {
    const item = itemsRef.current.find(n => n.id === id);
    if (!item || item.read_at || pending.current.has(id) || bulkPending.current) return;
    if (usingMock && !USE_MOCK) { setError('البيانات تجريبية — تعذّر حفظ القراءة'); return; }
    ctrlRef.current?.abort();
    refreshCtrl.current?.abort();
    setLoading(false);
    pending.current.add(id);
    const now = new Date().toISOString();
    setItems(prev => prev.map(n => n.id === id ? { ...n, read_at: now } : n));
    setUnread(u => Math.max(0, u - 1));
    try {
      if (!USE_MOCK) {
        const r = await notificationsApi.markRead(id);
        if (mounted.current) {
          setError(null);
          setUnread(r.meta.unread_count);
          setItems(prev => prev.map(n => n.id === id ? { ...n, read_at: r.data.read_at } : n));
        }
      }
    } catch (e) {
      if (mounted.current) {
        setItems(prev => prev.map(n => n.id === id ? { ...n, read_at: item.read_at } : n));
        setUnread(u => u + 1);
        const message = parseApiError(e).message; setError(message); onErrorRef.current?.(message);
      }
    } finally { pending.current.delete(id); }
  }, [usingMock]);
  const markAllRead = useCallback(async () => {
    if (bulkPending.current || pending.current.size > 0) return;
    if (usingMock && !USE_MOCK) {
      setError('البيانات تجريبية — تعذّر حفظ القراءة');
      return;
    }
    bulkPending.current = true;
    ctrlRef.current?.abort(); refreshCtrl.current?.abort(); setLoading(false);
    const prev = itemsRef.current;
    const prevUnread = unreadRef.current;
    const now = new Date().toISOString();
    setItems(items => items.map(n => ({ ...n, read_at: n.read_at ?? now })));
    setUnread(0);
    try {
      const r = USE_MOCK ? { meta: { unread_count: 0 } } : await notificationsApi.readAll();
      if (mounted.current) {
        setError(null);
        setUnread(r.meta.unread_count);
      }
    } catch (e) {
      if (mounted.current) {
        setItems(prev);
        setUnread(prevUnread);
        const message = parseApiError(e).message; setError(message); onErrorRef.current?.(message);
      }
    } finally { bulkPending.current = false; }
  }, [usingMock]);
  const loadMore = useCallback(() => { if (!loading && !bulkPending.current && page < lastPage) void load(page + 1); }, [loading, page, lastPage, load]);
  return { items, unread, loading, error, usingMock, markRead, markAllRead, loadMore, hasMore: page < lastPage, reload: () => load(1) };
}
