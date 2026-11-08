import { API_ENDPOINTS } from '@alfarid/api-client';
import type {
  Branch,
  CashierUser,
  CreateSalePayload,
  Customer,
  PastSale,
  PosCartLine,
  PosHeldSale,
  PosProduct,
  PosSettings,
  PosShift,
  ProductCategory,
  QuoteResult,
  ShiftStats,
  StockTransfer,
  StocktakeSession,
  Treasury,
  TransferSource,
} from '../types/pos.types';
import { normAr } from '../lib/arabic';
import { normalizeError, PosApiError } from '../lib/pos-error';
import { toApi, toCents } from '../lib/money';
import {
  mapCustomer,
  mapHeld,
  mapPastSale,
  mapProduct,
  mapQuote,
  mapShift,
  mapTransfer,
  mapTransferSource,
} from './pos-mappers';

const EP = API_ENDPOINTS.POS;
const CATALOG_STALE_MS = 60_000;
const LOCK_RETRY_DELAYS = [300, 900];

export interface PosHttp {
  get(url: string, config?: any): Promise<any>;
  post(url: string, data?: any, config?: any): Promise<any>;
  delete(url: string, config?: any): Promise<any>;
}

export interface PosApiOptions {
  sleep?: (ms: number) => Promise<void>;
}

export interface QuoteRequest {
  items: { product_variant_id: number; qty: number; discount_amount?: number; unit_price?: number }[];
  customer_id?: number | null;
  override_price?: boolean;
}

export interface ActorInput {
  id?: number;
  name?: string;
  roles?: (string | { name: string })[];
  permissions?: string[];
  branch_id?: number | null;
  branch?: { id: number; name: string; code?: string } | null;
}

const defaultSleep = (ms: number) => new Promise<void>((r) => setTimeout(r, ms));
const uuid = () => globalThis.crypto.randomUUID();
const initialsOf = (name: string) => {
  const w = name.trim().split(/\s+/);
  return w.length >= 2 ? `${w[0][0] ?? ''}${w[1][0] ?? ''}` : name.trim().slice(0, 2);
};

/** Same public surface and signatures as PosMockService, backed by the real /api/v1/pos endpoints. */
export class PosApiService {
  private branch: Branch | null = null;
  private cashier: CashierUser | null = null;
  private drawers: Treasury[] | null = null;
  private categories: ProductCategory[] | null = null;
  private settings: PosSettings = { max_discount_percent: 100, currency: 'EGP' };
  private heldCount = 0;
  private currentShift: PosShift | null = null;
  private shiftLoaded = false;

  private byId = new Map<number, PosProduct>();
  private byCode = new Map<string, PosProduct>();
  private searchIndex: { p: PosProduct; key: string }[] = [];
  private catalogEtag: string | null = null;
  private catalogLoadedAt = 0;
  private catalogInflight: Promise<void> | null = null;

  private held: PosHeldSale[] = [];
  private transfersIn: StockTransfer[] = [];
  private stocktakeId: number | null = null;
  private idemDrafts = new Map<string, { hash: string; key: string }>();
  private listeners = new Set<() => void>();
  private sleep: (ms: number) => Promise<void>;

  constructor(private http: PosHttp, opts: PosApiOptions = {}) {
    this.sleep = opts.sleep ?? defaultSleep;
  }

  // ---- subscribe / notify ----
  public subscribe(cb: () => void): () => void {
    this.listeners.add(cb);
    return () => this.listeners.delete(cb);
  }

  private notify(): void {
    this.listeners.forEach((cb) => cb());
  }

  /** Normalizes errors, clears the shift on no_open_shift, retries 409 lock conflicts (300ms, 900ms). */
  private async call<T>(fn: () => Promise<T>): Promise<T> {
    let attempt = 0;
    for (;;) {
      try {
        return await fn();
      } catch (e) {
        const err = normalizeError(e);
        if (err.status === 403 && err.code === 'no_open_shift') {
          this.currentShift = null;
          this.shiftLoaded = true;
          this.notify();
        }
        if (err.status === 409 && err.retryable && attempt < LOCK_RETRY_DELAYS.length) {
          await this.sleep(LOCK_RETRY_DELAYS[attempt++]);
          continue;
        }
        throw err;
      }
    }
  }

  /** Same payload → same key (server replays the same sale); different payload → new key. */
  private idemKey(scope: string, payload: unknown): string {
    const hash = JSON.stringify(payload);
    const cur = this.idemDrafts.get(scope);
    if (cur && cur.hash === hash) return cur.key;
    const draft = { hash, key: uuid() };
    this.idemDrafts.set(scope, draft);
    return draft.key;
  }

  // ---- bootstrap / actor ----
  public syncActor(actor?: ActorInput | null): void {
    if (!actor || !actor.name) return;
    const first = actor.roles?.[0];
    this.cashier = {
      id: actor.id ?? this.cashier?.id ?? 0,
      name: actor.name,
      initials: initialsOf(actor.name),
      role: (typeof first === 'string' ? first : first?.name) || 'كاشير',
      perms: actor.permissions ?? [],
    };
    if (actor.branch?.name) {
      this.branch = {
        id: actor.branch.id || actor.branch_id || 0,
        name: actor.branch.name,
        code: actor.branch.code ?? this.branch?.code ?? '',
        is_active: true,
      };
    }
    this.notify();
  }

  public async bootstrap(): Promise<void> {
    const r = await this.call(() => this.http.get(EP.BOOTSTRAP));
    const d = r.data.data;
    this.syncActor({ ...d.user, branch: d.branch });
    this.branch = { id: d.branch.id, name: d.branch.name, code: d.branch.code, is_active: true };
    this.currentShift = d.shift ? mapShift(d.shift) : null;
    this.shiftLoaded = true;
    this.drawers = this.mapDrawers(d.drawers);
    this.categories = d.categories;
    this.settings = d.settings;
    this.heldCount = d.held_count ?? 0;
    this.notify();
    // Catalog failures are non-fatal here; screens call loadCatalog() again.
    await this.loadCatalog().catch(() => undefined);
  }

  public getSettings(): PosSettings {
    return { ...this.settings };
  }

  public getHeldCount(): number {
    return this.heldCount;
  }

  public async getBranch(): Promise<Branch> {
    if (!this.branch) await this.bootstrap();
    return { ...(this.branch as Branch) };
  }

  public async getCashier(): Promise<CashierUser> {
    if (!this.cashier) await this.bootstrap();
    return { ...(this.cashier as CashierUser) };
  }

  // ---- shifts ----
  private mapDrawers(list: any[]): Treasury[] {
    return list.map((t) => ({
      id: t.id,
      name: t.name,
      code: t.code,
      state: t.busy ? 'busy' : 'free',
      last: t.busy ? 'مشغول حاليًا' : `رصيد: ${t.balance ?? '0.00'} ج.م`,
      balance: Number(t.balance ?? 0),
    }));
  }

  public async getTreasuries(): Promise<Treasury[]> {
    if (!this.drawers) {
      const r = await this.call(() => this.http.get(EP.TREASURIES_AVAILABLE));
      this.drawers = this.mapDrawers(r.data.data);
    }
    return this.drawers.map((t) => ({ ...t }));
  }

  public async getCurrentShift(): Promise<PosShift | null> {
    if (!this.shiftLoaded) {
      const r = await this.call(() => this.http.get(EP.SHIFTS_CURRENT));
      this.currentShift = r.data.data ? mapShift(r.data.data) : null;
      this.shiftLoaded = true;
    }
    return this.currentShift ? { ...this.currentShift } : null;
  }

  public async openShift(treasuryId: number, openingCash: number): Promise<PosShift> {
    const r = await this.call(() => this.http.post(EP.SHIFTS_OPEN, { treasury_id: treasuryId, opening_cash: openingCash }));
    this.currentShift = mapShift(r.data.data);
    this.shiftLoaded = true;
    this.drawers = null;
    this.notify();
    return { ...this.currentShift };
  }

  public async closeShift(actualClosingCash: number, notes?: string): Promise<PosShift> {
    const r = await this.call(() =>
      this.http.post(EP.SHIFTS_CLOSE, { actual_closing_cash: actualClosingCash, notes: notes || null }),
    );
    const closed = mapShift(r.data.data);
    this.currentShift = null;
    this.shiftLoaded = true;
    this.drawers = null;
    this.notify();
    return closed;
  }

  public async getShiftStats(): Promise<ShiftStats> {
    const r = await this.call(() => this.http.get(EP.SHIFT_SUMMARY));
    const s = r.data.data;
    if (this.currentShift) this.currentShift = mapShift(s);
    return {
      cashSales: Number(s.cash_sales_total),
      cardSales: Number(s.card_sales_total),
      returns: Number(s.cash_returns_total),
      expenses: Number(s.cash_expenses_total),
      invoices: Number(s.invoices_count ?? 0),
    };
  }

  private async refreshShift(): Promise<void> {
    try {
      await this.getShiftStats();
      this.notify();
    } catch {
      /* stats refresh is best-effort */
    }
  }

  // ---- catalog (in-memory indexes) ----
  private index(p: PosProduct): void {
    this.byId.set(p.id, p);
    for (const code of [p.barcode, p.sku]) {
      if (code) this.byCode.set(normAr(code), p);
    }
    this.searchIndex.push({ p, key: normAr(`${p.name} ${p.sku} ${p.barcode}`) });
  }

  private rebuild(items: any[]): void {
    this.byId = new Map();
    this.byCode = new Map();
    this.searchIndex = [];
    items.forEach((i) => this.index(mapProduct(i)));
  }

  public loadCatalog(force = false): Promise<void> {
    if (!force && this.byId.size && Date.now() - this.catalogLoadedAt < CATALOG_STALE_MS) return Promise.resolve();
    if (this.catalogInflight) return this.catalogInflight;
    this.catalogInflight = this.fetchCatalog().finally(() => {
      this.catalogInflight = null;
    });
    return this.catalogInflight;
  }

  private async fetchCatalog(): Promise<void> {
    const headers: Record<string, string> = {};
    if (this.catalogEtag) headers['If-None-Match'] = this.catalogEtag;
    const first = await this.call(() =>
      this.http.get(EP.CATALOG, { headers, validateStatus: (s: number) => (s >= 200 && s < 300) || s === 304 }),
    );
    if (first.status === 304) {
      this.catalogLoadedAt = Date.now();
      return;
    }
    const items: any[] = [...first.data.data];
    let cursor: string | null = first.data.meta?.next_cursor ?? null;
    while (cursor) {
      const next = await this.call(() => this.http.get(EP.CATALOG, { params: { cursor } }));
      items.push(...next.data.data);
      cursor = next.data.meta?.next_cursor ?? null;
    }
    this.rebuild(items);
    this.catalogEtag = first.headers?.etag ?? first.headers?.ETag ?? null;
    this.catalogLoadedAt = Date.now();
    this.notify();
  }

  public async getCategories(): Promise<ProductCategory[]> {
    if (!this.categories) await this.bootstrap();
    return [{ id: 'all', name: 'الكل' }, ...(this.categories ?? [])];
  }

  public async getProducts(cat = 'all', query = '', lowStockOnly = false): Promise<PosProduct[]> {
    await this.loadCatalog();
    const q = normAr(query);
    return this.searchIndex
      .filter(({ p, key }) => (cat === 'all' || p.cat === cat) && (!q || key.includes(q)) && (!lowStockOnly || p.stock <= p.reorder))
      .map(({ p }) => p);
  }

  /** Synchronous on purpose: components call it during render. Reads the local catalog index. */
  public getProductById(id: number): PosProduct | undefined {
    return this.byId.get(id);
  }

  /** Local index first (0ms); falls back to products/lookup and caches the hit. */
  public async lookupByBarcode(code: string): Promise<PosProduct | null> {
    const key = normAr(code);
    if (!key) return null;
    const local = this.byCode.get(key);
    if (local) return local;
    const r = await this.call(() => this.http.get(EP.PRODUCTS_LOOKUP, { params: { barcode: key } }));
    const hit = r.data.data?.[0];
    if (!hit) return null;
    const p = mapProduct(hit);
    this.index(p);
    return p;
  }

  // ---- customers ----
  public async getCustomers(query = ''): Promise<Customer[]> {
    const r = await this.call(() => this.http.get(EP.CUSTOMERS, { params: { q: query.trim() } }));
    return r.data.data.map(mapCustomer);
  }

  public async createQuickCustomer(data: { name: string; phone: string; tier: 'retail' | 'wholesale' }): Promise<Customer> {
    const r = await this.call(() => this.http.post(EP.CUSTOMERS, { name: data.name, phone: data.phone, type: data.tier }));
    this.notify();
    return mapCustomer(r.data.data);
  }

  // ---- pricing ----
  public async quoteSale(req: QuoteRequest, signal?: AbortSignal): Promise<QuoteResult> {
    const body = {
      items: req.items.map((i) => ({
        product_variant_id: i.product_variant_id,
        qty: i.qty,
        discount_amount: toApi(toCents(i.discount_amount ?? 0)),
        ...(req.override_price && i.unit_price != null ? { unit_price: toApi(toCents(i.unit_price)) } : {}),
      })),
      customer_id: req.customer_id ?? null,
      ...(req.override_price ? { override_price: true } : {}),
    };
    const r = await this.call(() => this.http.post(EP.SALES_QUOTE, body, { signal }));
    return mapQuote(r.data.data);
  }

  // ---- sales ----
  public async createSale(
    payload: CreateSalePayload,
    idempotencyKey?: string,
  ): Promise<{ order: PastSale; invoiceNumber: string }> {
    const body = {
      items: payload.items.map((i) => ({
        product_variant_id: i.product_variant_id,
        qty: i.qty,
        discount_amount: toApi(toCents(i.discount_amount ?? 0)),
        ...(payload.override_price ? { unit_price: toApi(toCents(i.unit_price)) } : {}),
      })),
      customer_id: payload.customer_id,
      payment_method: payload.payment_method,
      cash_amount: toApi(toCents(payload.cash_amount)),
      card_amount: toApi(toCents(payload.card_amount)),
      ...(payload.override_price ? { override_price: true } : {}),
      notes: payload.notes ?? null,
    };
    const key = idempotencyKey ?? this.idemKey('sale', body);
    const r = await this.call(() =>
      this.http.post(EP.SALES, body, { headers: { 'Idempotency-Key': key }, timeout: 15000 }),
    );
    this.idemDrafts.delete('sale');
    const d = r.data.data;
    for (const i of payload.items) {
      const p = this.byId.get(i.product_variant_id);
      if (p) p.stock = Math.max(0, p.stock - i.qty);
    }
    const order: PastSale = {
      id: Number(d.id),
      number: d.order_number,
      date: new Date().toLocaleString('ar-EG'),
      cashier: this.cashier?.name ?? '',
      customer: 'عميل نقدي',
      method: payload.payment_method === 'cash' ? 'نقدي' : payload.payment_method === 'card' ? 'بطاقة' : 'مختلط',
      items: payload.items.map((i) => ({ id: i.product_variant_id, qty: i.qty, price: i.unit_price })),
    };
    void this.refreshShift();
    this.notify();
    return { order, invoiceNumber: d.order_number };
  }

  // ---- held sales ----
  public async getHeldSales(): Promise<PosHeldSale[]> {
    const r = await this.call(() => this.http.get(EP.SALES_HELD));
    this.held = r.data.data.map(mapHeld);
    this.heldCount = this.held.length;
    return [...this.held];
  }

  private heldId(ref: string): number {
    const id = this.held.find((h) => h.ref === ref)?.serverId;
    if (!id) throw new PosApiError(404, 'الفاتورة المعلقة غير موجودة', 'not_found');
    return id;
  }

  public async holdSale(cart: PosCartLine[], customer: Customer | null, note: string): Promise<PosHeldSale> {
    const r = await this.call(() =>
      this.http.post(EP.SALES_HOLD, {
        items: cart.map((c) => ({ product_variant_id: c.id, qty: c.qty, discount_amount: '0.00' })),
        customer_id: customer?.id ?? null,
        notes: note,
      }),
    );
    const h = mapHeld(r.data.data);
    this.held.unshift(h);
    this.heldCount = this.held.length;
    this.notify();
    return { ...h };
  }

  /** Atomic server-side read+delete; never GET then DELETE. */
  public async resumeHeldSale(ref: string): Promise<PosHeldSale> {
    const id = this.heldId(ref);
    const r = await this.call(() => this.http.post(EP.SALE_HELD_RESUME(id)));
    this.held = this.held.filter((h) => h.ref !== ref);
    this.heldCount = this.held.length;
    this.notify();
    return mapHeld(r.data.data);
  }

  public async deleteHeldSale(ref: string): Promise<void> {
    const id = this.heldId(ref);
    await this.call(() => this.http.delete(EP.SALE_HELD_DELETE(id)));
    this.held = this.held.filter((h) => h.ref !== ref);
    this.heldCount = this.held.length;
    this.notify();
  }

  // ---- returns & exchange ----
  public async getPastSale(num: string): Promise<PastSale | null> {
    try {
      const r = await this.call(() => this.http.get(EP.SALES_LOOKUP, { params: { number: num.trim() } }));
      return mapPastSale(r.data.data);
    } catch (e) {
      if (e instanceof PosApiError && e.status === 404) return null;
      throw e;
    }
  }

  private groupReturnLines(items: { id: number; qty: number; return_to: 'on_hand' | 'damaged' }[]) {
    const m = new Map<string, { product_variant_id: number; qty: number; return_to: string }>();
    for (const i of items) {
      const k = `${i.id}|${i.return_to}`;
      const cur = m.get(k);
      if (cur) cur.qty += i.qty;
      else m.set(k, { product_variant_id: i.id, qty: i.qty, return_to: i.return_to });
    }
    return [...m.values()];
  }

  private restock(items: { id: number; qty: number; return_to: string }[]): void {
    for (const i of items) {
      const p = this.byId.get(i.id);
      if (p && i.return_to === 'on_hand') p.stock += i.qty;
    }
  }

  public async processReturn(
    saleId: number,
    items: { id: number; qty: number; return_to: 'on_hand' | 'damaged' }[],
  ): Promise<void> {
    await this.call(() => this.http.post(EP.SALE_RETURN(saleId), { items: this.groupReturnLines(items) }));
    this.restock(items);
    void this.refreshShift();
    this.notify();
  }

  /** cash+card = total of the NEW items (from a server quote), not the net difference. */
  public async processExchange(
    saleId: number,
    returnItems: { id: number; qty: number; return_to: 'on_hand' | 'damaged' }[],
    exchangeItems: { id: number; qty: number }[],
    _netDiff: number,
  ): Promise<void> {
    const quote = await this.quoteSale({ items: exchangeItems.map((i) => ({ product_variant_id: i.id, qty: i.qty })) });
    await this.call(() =>
      this.http.post(EP.SALE_EXCHANGE(saleId), {
        returned_items: this.groupReturnLines(returnItems),
        exchange_items: exchangeItems.map((i) => ({ product_variant_id: i.id, qty: i.qty })),
        cash_amount: toApi(toCents(quote.grand_total)),
        card_amount: '0.00',
      }),
    );
    this.restock(returnItems);
    for (const i of exchangeItems) {
      const p = this.byId.get(i.id);
      if (p) p.stock = Math.max(0, p.stock - i.qty);
    }
    void this.refreshShift();
    this.notify();
  }

  // ---- transfers ----
  public async getTransfersIn(): Promise<StockTransfer[]> {
    const r = await this.call(() => this.http.get(EP.TRANSFERS_INCOMING));
    this.transfersIn = r.data.data.map(mapTransfer);
    return this.transfersIn.map((t) => ({ ...t }));
  }

  /** `quantities` is keyed by variant id; the server wants stock_transfer_items.id. */
  public async receiveTransfer(transferId: number, quantities: Record<number, number>): Promise<void> {
    const t = this.transfersIn.find((x) => x.id === transferId);
    if (!t) throw new PosApiError(404, 'التحويل غير موجود', 'not_found');
    const items = t.items.map((i) => ({ id: i.transferItemId, received_qty: quantities[i.id] ?? i.qty }));
    await this.call(() => this.http.post(EP.TRANSFER_RECEIVE(transferId), { items }));
    this.transfersIn = this.transfersIn.filter((x) => x.id !== transferId);
    void this.loadCatalog(true).catch(() => undefined);
    this.notify();
  }

  public async getTransferSources(): Promise<TransferSource[]> {
    const r = await this.call(() => this.http.get(EP.TRANSFER_SOURCES));
    return r.data.data.map(mapTransferSource);
  }

  public async requestTransfer(fromBranchId: number, items: { id: number; qty: number }[], notes?: string): Promise<string> {
    const body = {
      from_branch_id: fromBranchId,
      items: items.map((i) => ({ product_variant_id: i.id, requested_qty: i.qty })),
      notes: notes ?? null,
    };
    const key = this.idemKey('transfer', body);
    const r = await this.call(() => this.http.post(EP.TRANSFER_REQUESTS, body, { headers: { 'Idempotency-Key': key } }));
    this.idemDrafts.delete('transfer');
    this.notify();
    return r.data.data?.transfer_number ?? '';
  }

  // ---- stocktake ----
  /** No open session is reported as `session.id === 0` (screens render "لا توجد جلسة جرد مفتوحة"). */
  public async getStocktakeSession(): Promise<{ session: StocktakeSession; scope: PosProduct[] }> {
    const r = await this.call(() => this.http.get(EP.STOCKTAKES_CURRENT));
    const d = r.data.data;
    if (!d) {
      this.stocktakeId = null;
      return { session: { id: 0, code: '', status: 'draft', counts: {}, log: [] }, scope: [] };
    }
    this.stocktakeId = d.id;
    const counts: Record<number, number> = {};
    for (const i of d.items) counts[i.product_variant_id] = Number(i.counted_qty ?? 0);
    const scope = d.items.map((i: any) => this.byId.get(i.product_variant_id)).filter(Boolean) as PosProduct[];
    return {
      session: { id: d.id, code: d.stocktake_number, status: d.status, counts, log: [] },
      scope,
    };
  }

  public async recordStocktakeScan(
    barcodeOrVariantId: string | number,
    qty?: number,
    via: 'voice' | 'barcode' = 'barcode',
    words?: string,
    scanId: string = uuid(),
  ): Promise<{ duplicate: boolean }> {
    if (!this.stocktakeId) await this.getStocktakeSession();
    if (!this.stocktakeId) throw new PosApiError(404, 'لا توجد جلسة جرد مفتوحة', 'no_stocktake');
    const barcode =
      typeof barcodeOrVariantId === 'number' ? this.byId.get(barcodeOrVariantId)?.barcode ?? '' : barcodeOrVariantId;
    const id = this.stocktakeId;
    const r = await this.call(() =>
      this.http.post(EP.STOCKTAKE_SCAN(id), {
        barcode,
        scan_id: scanId,
        ...(via === 'voice' && words ? { qty_words: words } : { qty }),
      }),
    );
    const duplicate = Boolean(r.data?.duplicate ?? r.data?.data?.duplicate);
    if (!duplicate) this.notify();
    return { duplicate };
  }
}
