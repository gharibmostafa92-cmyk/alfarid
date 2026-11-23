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
  PosStockRow,
  ProcessReturnResult,
  PosShift,
  ProductCategory,
  QuoteResult,
  ShiftStats,
  StockTransfer,
  StocktakeSession,
  Treasury,
  TransferSource,
} from '../types/pos.types';
import { clearReceiptArchive } from '../lib/receipt-archive';
import { clearPendingSaleStorage } from '../lib/pending-sale';
import { clearPersistedCarts } from '../stores/pos-cart.core';
import { normAr } from '../lib/arabic';
import { normalizeError, PosApiError } from '../lib/pos-error';
import { toApi, toCents, spreadDiscount } from '../lib/money';
import { runPosSaleWithTrace } from './pos-sale-tracing';
export { configurePosSaleTracing } from './pos-sale-tracing';
export type { PosSaleTrace } from './pos-sale-tracing';
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
const DEFAULT_TIMEOUT_MS = 15000;
const CATALOG_STALE_MS = 60_000;
const LOCK_RETRY_DELAYS = [300, 900];
const MAX_503_RETRIES = 3;

export interface PosHttp {
  get(url: string, config?: any): Promise<any>;
  post(url: string, data?: any, config?: any): Promise<any>;
  delete(url: string, config?: any): Promise<any>;
}

export interface PosApiOptions {
  sleep?: (ms: number) => Promise<void>;
}

export interface ExchangePaymentOptions {
  method?: 'cash' | 'card' | 'mixed';
  cashAmount?: number;
  cardAmount?: number;
}

export interface QuoteRequest {
  items: { product_variant_id: number; qty: number; discount_amount?: number; unit_price?: number; override_reason?: string }[];
  customer_id?: number | null;
  override_price?: boolean;
}

export interface ActorInput {
  version?: number;
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
  private catalogActorGeneration = 0;
  private catalogLoadedAt = 0;
  private catalogInflight: Promise<void> | null = null;

  private held: PosHeldSale[] = [];
  private bootstrapInflight: Promise<void> | null = null;
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

  /** Normalizes errors, clears the shift on no_open_shift, retries 409 lock conflicts (300ms, 900ms)
   * and 503s (Retry-After, capped). */
  private async call<T>(fn: () => Promise<T>): Promise<T> {
    let attempt = 0;
    let unavailableAttempts = 0;
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
        if (err.status === 503 && err.retryable && unavailableAttempts < MAX_503_RETRIES) {
          unavailableAttempts += 1;
          await this.sleep(err.retryAfterMs ?? 1000);
          continue;
        }
        throw err;
      }
    }
  }

  /** Same payload → same key (server replays the same sale); different payload → new key. */
  private idemKey(scope: string, payload: unknown): string {
    const hash = JSON.stringify(payload ?? null);
    const cur = this.idemDrafts.get(scope);
    if (cur && cur.hash === hash) return cur.key;
    const draft = { hash, key: uuid() };
    this.idemDrafts.set(scope, draft);
    return draft.key;
  }

  private withTimeout(config: any = {}): any {
    return { timeout: DEFAULT_TIMEOUT_MS, ...config };
  }

  private async postIdempotent<T = any>(
    url: string,
    scope: string,
    body: unknown,
    explicitKey?: string,
    runner: <R>(fn: () => Promise<R>) => Promise<R> = (fn) => fn(),
  ): Promise<T> {
    const key = explicitKey ?? this.idemKey(scope, body);
    const response = await runner(() =>
      this.call(() => this.http.post(url, body, this.withTimeout({ headers: { 'Idempotency-Key': key } }))),
    );
    this.idemDrafts.delete(scope);
    return response;
  }

  private assertFiniteNumber(value: number): void {
    if (!Number.isFinite(value)) {
      throw new PosApiError(422, 'المبلغ المدخل غير صالح', 'validation');
    }
  }

  private assertNonNegativeAmount(amount: number): void {
    if (!Number.isFinite(amount) || amount < 0) {
      throw new PosApiError(422, 'المبلغ المدخل غير صالح', 'validation');
    }
  }

  private deductLocalStock(items: { id: number; qty: number }[]): void {
    for (const item of items) {
      const product = this.byId.get(item.id);
      if (product) product.stock = Math.max(0, product.stock - item.qty);
    }
  }

  // ---- bootstrap / actor ----
  public syncActor(actor?: ActorInput | null): void {
    if (!actor || !actor.name) return;
    const actorBranchId = actor.branch?.id || actor.branch_id || 0;
    const isNewUser = Boolean(this.cashier && actor.id != null && this.cashier.id !== actor.id);
    const isNewBranch = Boolean(this.branch && actorBranchId > 0 && this.branch.id !== actorBranchId);

    if (isNewUser) {
      clearReceiptArchive();
      clearPendingSaleStorage();
      clearPersistedCarts();
    }

    if (isNewUser || isNewBranch) {
      this.resetActorScopedState();
    }

    this.cashier = this.buildCashierFromActor(actor);
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

  private resetActorScopedState(): void {
    this.currentShift = null;
    this.shiftLoaded = false;
    this.drawers = null;
    this.held = [];
    this.heldCount = 0;
    this.transfersIn = [];
    this.stocktakeId = null;
    this.catalogEtag = null;
    this.catalogActorGeneration += 1;
    this.catalogLoadedAt = 0;
    this.byId.clear();
    this.byCode.clear();
    this.searchIndex = [];
    this.idemDrafts.clear();
  }

  private buildCashierFromActor(actor: ActorInput): CashierUser {
    const firstRole = actor.roles?.[0];
    return {
      id: actor.id ?? this.cashier?.id ?? 0,
      name: actor.name ?? '',
      initials: initialsOf(actor.name ?? ''),
      role: (typeof firstRole === 'string' ? firstRole : firstRole?.name) || 'كاشير',
      version: actor.version,
      perms: actor.permissions ?? [],
    };
  }

  public bootstrap(): Promise<void> {
    this.bootstrapInflight ??= this.fetchBootstrap().finally(() => {
      this.bootstrapInflight = null;
    });
    return this.bootstrapInflight;
  }

  private async fetchBootstrap(): Promise<void> {
    const r = await this.call(() => this.http.get(EP.BOOTSTRAP, this.withTimeout()));
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
    // The shift gate can render while the catalog loads in the background.
    void this.loadCatalog().catch(() => undefined);
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
      const r = await this.call(() => this.http.get(EP.TREASURIES_AVAILABLE, this.withTimeout()));
      this.drawers = this.mapDrawers(r.data.data);
    }
    return this.drawers.map((t) => ({ ...t }));
  }

  public async getCurrentShift(): Promise<PosShift | null> {
    if (!this.shiftLoaded) {
      const r = await this.call(() => this.http.get(EP.SHIFTS_CURRENT, this.withTimeout()));
      this.currentShift = r.data.data ? mapShift(r.data.data) : null;
      this.shiftLoaded = true;
    }
    return this.currentShift ? { ...this.currentShift } : null;
  }

  public async openShift(treasuryId: number, openingCash: number): Promise<PosShift> {
    // F-10: stable key per payload so a retried open replays the same shift.
    const r = await this.postIdempotent(EP.SHIFTS_OPEN, 'shift-open', {
      treasury_id: treasuryId,
      opening_cash: openingCash,
    });
    this.currentShift = mapShift(r.data.data);
    this.shiftLoaded = true;
    this.drawers = null;
    this.notify();
    return { ...this.currentShift };
  }

  public async closeShift(actualClosingCash: number, notes?: string): Promise<PosShift> {
    // F-10: stable key per payload so a retried close replays the summary (F-15: finite guard).
    this.assertNonNegativeAmount(actualClosingCash);
    const r = await this.postIdempotent(EP.SHIFTS_CLOSE, 'shift-close', {
      actual_closing_cash: actualClosingCash,
      notes: notes || null,
    });
    const closed = mapShift(r.data.data);
    this.currentShift = null;
    this.shiftLoaded = true;
    this.drawers = null;
    this.notify();
    return closed;
  }

  public async recordExpense(amount: number, category: string, notes?: string, idempotencyKey?: string): Promise<any> {
    const r = await this.postIdempotent(
      EP.SALES_EXPENSES,
      'expense',
      { amount, category, notes: notes || null },
      idempotencyKey || undefined,
    );
    await this.refreshShift();
    return r.data;
  }

  public async getShiftStats(): Promise<ShiftStats> {
    const r = await this.call(() => this.http.get(EP.SHIFT_SUMMARY, this.withTimeout()));
    const s = r.data.data;
    if (this.currentShift) this.currentShift = mapShift(s);
    // Blind cashiers get no cash fields from the server: default to 0, never NaN.
    const canViewExpected = s.expected_closing_cash != null;
    return {
      cashSales: Number(s.cash_sales_total ?? 0),
      cardSales: Number(s.card_sales_total ?? 0),
      returns: Number(s.cash_returns_total ?? 0),
      expenses: Number(s.cash_expenses_total ?? 0),
      invoices: Number(s.invoices_count ?? 0),
      expected_closing_cash: canViewExpected ? Number(s.expected_closing_cash) : undefined,
      canViewExpected,
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
    if (!force && Date.now() - this.catalogLoadedAt < CATALOG_STALE_MS) return Promise.resolve();
    if (this.catalogInflight) return this.catalogInflight;
    this.catalogInflight = this.fetchCatalog().finally(() => {
      this.catalogInflight = null;
    });
    return this.catalogInflight;
  }

  private async fetchCatalog(): Promise<void> {
    const generation = this.catalogActorGeneration;
    for (let attempt = 0; ; attempt++) {
      try {
        await this.fetchCatalogPages(generation);
        return;
      } catch (error) {
        if (generation !== this.catalogActorGeneration) return;
        if (normalizeError(error).status !== 412 || attempt === 3) throw error;
        this.catalogEtag = null;
        await this.sleep(100 + Math.random() * 150);
      }
    }
  }

  private async fetchCatalogPages(generation: number): Promise<void> {
    const headers: Record<string, string> = {};
    if (this.catalogEtag) headers['If-None-Match'] = this.catalogEtag;
    const first = await this.call(() =>
      this.http.get(EP.CATALOG, this.withTimeout({ headers, validateStatus: (s: number) => (s >= 200 && s < 300) || s === 304 })),
    );
    if (generation !== this.catalogActorGeneration) return;
    if (first.status === 304) {
      this.catalogLoadedAt = Date.now();
      return;
    }
    const items: any[] = [...first.data.data];
    const version = first.data.meta?.catalog_version;
    const paginationVersion = first.data.meta?.pagination_version ?? version;
    let cursor: string | null = first.data.meta?.next_cursor ?? null;
    while (cursor) {
      const next = await this.call(() => this.http.get(EP.CATALOG, this.withTimeout({ params: { cursor, catalog_version: paginationVersion } })));
      if (generation !== this.catalogActorGeneration) return;
      items.push(...next.data.data);
      cursor = next.data.meta?.next_cursor ?? null;
    }
    this.rebuild(items);
    this.catalogEtag = first.headers?.etag ?? first.headers?.ETag ?? (version ? `"${version}"` : null);
    this.catalogLoadedAt = Date.now();
    this.notify();
  }

  public async getCategories(): Promise<ProductCategory[]> {
    if (!this.categories) await this.bootstrap();
    return [{ id: 'all', name: 'الكل' }, ...(this.categories ?? [])];
  }

  public async getStockPage(query: string, low: boolean, page: number): Promise<{ rows: PosStockRow[]; lastPage: number }> {
    const response = await this.call(() => this.http.get(EP.STOCK, this.withTimeout({
      params: { q: query || undefined, low_stock: low ? 1 : 0, page, per_page: 20 },
    })));
    return { rows: response.data.data, lastPage: response.data.meta.last_page };
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
    const r = await this.call(() => this.http.get(EP.PRODUCTS_LOOKUP, this.withTimeout({ params: { barcode: key } })));
    const hit = r.data.data?.[0];
    if (!hit) return null;
    const p = mapProduct(hit);
    this.index(p);
    return p;
  }

  // ---- customers ----
  public async getCustomers(query = '', signal?: AbortSignal): Promise<Customer[]> {
    const r = await this.call(() => this.http.get(EP.CUSTOMERS, this.withTimeout({ params: { q: query.trim() }, signal })));
    return r.data.data.map(mapCustomer);
  }

  public async createQuickCustomer(data: { name: string; phone: string; tier: 'retail' | 'wholesale' }): Promise<Customer> {
    const r = await this.call(() => this.http.post(EP.CUSTOMERS, { name: data.name, phone: data.phone, type: data.tier }, this.withTimeout()));
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
    const r = await this.call(() => this.http.post(EP.SALES_QUOTE, body, this.withTimeout({ signal })));
    return mapQuote(r.data.data);
  }

  // ---- sales ----
  public async createSale(
    payload: CreateSalePayload,
    idempotencyKey?: string,
  ): Promise<{ order: PastSale; invoiceNumber: string }> {
    // F-15: never send NaN/Infinity totals to the server.
    this.assertNonNegativeAmount(payload.cash_amount);
    this.assertNonNegativeAmount(payload.card_amount);

    const body = this.buildCreateSaleBody(payload);
    const r = await this.postIdempotent(EP.SALES, 'sale', body, idempotencyKey, runPosSaleWithTrace);
    const d = r.data.data;

    this.deductLocalStock(payload.items.map((i) => ({ id: i.product_variant_id, qty: i.qty })));
    const order = this.buildPastSaleSummary(Number(d.id), d.order_number, payload);
    void this.refreshShift();
    this.notify();
    return { order, invoiceNumber: d.order_number };
  }

  private buildCreateSaleBody(payload: CreateSalePayload) {
    return {
      items: payload.items.map((i) => {
        const reason = i.override_reason ?? payload.override_reason;
        const isManual = i.unit_price !== undefined && (payload.override_price || Boolean(i.override_reason));
        return {
          product_variant_id: i.product_variant_id,
          qty: i.qty,
          discount_amount: toApi(toCents(i.discount_amount ?? 0)),
          ...(isManual ? { unit_price: toApi(toCents(i.unit_price!)) } : {}),
          ...(isManual && reason ? { override_reason: reason } : {}),
        };
      }),
      customer_id: payload.customer_id,
      payment_method: payload.payment_method,
      cash_amount: toApi(toCents(payload.cash_amount)),
      card_amount: toApi(toCents(payload.card_amount)),
      ...(payload.override_price ? { override_price: true } : {}),
      ...(payload.held_sale_id != null ? { held_sale_id: payload.held_sale_id } : {}),
      ...(payload.held_sale_ids && payload.held_sale_ids.length > 0 ? { held_sale_ids: payload.held_sale_ids } : {}),
      notes: payload.notes ?? null,
    };
  }

  private buildPastSaleSummary(orderId: number, orderNumber: string, payload: CreateSalePayload): PastSale {
    return {
      id: orderId,
      number: orderNumber,
      date: new Date().toLocaleString('ar-EG'),
      cashier: this.cashier?.name ?? '',
      customer: 'عميل نقدي',
      method: payload.payment_method === 'cash' ? 'نقدي' : payload.payment_method === 'card' ? 'بطاقة' : 'مختلط',
      items: payload.items.map((i) => ({
        id: i.product_variant_id,
        qty: i.qty,
        price: i.unit_price ?? this.byId.get(i.product_variant_id)?.price ?? 0,
      })),
    };
  }

  // ---- held sales ----
  public async getHeldSales(): Promise<PosHeldSale[]> {
    const r = await this.call(() => this.http.get(EP.SALES_HELD, this.withTimeout()));
    this.held = r.data.data.map(mapHeld);
    this.heldCount = this.held.length;
    return [...this.held];
  }

  private heldId(ref: string): number {
    const id = this.held.find((h) => h.ref === ref)?.serverId;
    if (!id) throw new PosApiError(404, 'الفاتورة المعلقة غير موجودة', 'not_found');
    return id;
  }

  public async holdSale(
    cart: PosCartLine[],
    customer: Customer | null,
    note: string,
    discountTotal = 0,
  ): Promise<PosHeldSale> {
    const shares = spreadDiscount(
      cart.map((c) => ({ id: c.id, totalC: toCents(c.price) * c.qty })),
      toCents(discountTotal),
    );
    const body = {
      items: cart.map((c) => ({
        product_variant_id: c.id,
        qty: c.qty,
        unit_price: toApi(toCents(c.price)),
        discount_amount: toApi(shares.find((s) => s.id === c.id)?.d ?? 0),
        // F-03: explicit manual flag — never inferred from price comparison.
        manual_price: Boolean(c.manualPrice),
      })),
      customer_id: customer?.id ?? null,
      notes: note,
    };
    const r = await this.postIdempotent(EP.SALES_HOLD, 'hold', body);
    const h = mapHeld(r.data.data);
    this.held.unshift(h);
    this.heldCount = this.held.length;
    this.notify();
    return { ...h };
  }

  /** Soft lease on resume: the held row stays in DB until createSale completes or explicit delete. */
  public async resumeHeldSale(ref: string): Promise<PosHeldSale> {
    const id = this.heldId(ref);
    const r = await this.postIdempotent(EP.SALE_HELD_RESUME(id), `resume:${id}`, undefined);
    // F-04: keep the row in the local drawer while the 5-minute server lease
    // is alive. Removing it locally hid the lease and produced ghost invoices
    // that reappeared with 409s; the row leaves on checkout or explicit delete.
    const resumed = mapHeld(r.data.data);
    const ix = this.held.findIndex((h) => h.ref === ref);
    if (ix >= 0) this.held[ix] = { ...resumed, ref };
    this.heldCount = this.held.length;
    this.notify();
    return resumed;
  }

  /** F-04: drop locally-held rows the server has consumed (checkout) or deleted. */
  public forgetHeldSaleRefs(serverIds: number[]): void {
    if (serverIds.length === 0) return;
    const gone = new Set(serverIds);
    this.held = this.held.filter((h) => !(h.serverId != null && gone.has(h.serverId)));
    this.heldCount = this.held.length;
    this.notify();
  }

  public async deleteHeldSale(ref: string): Promise<void> {
    const id = this.heldId(ref);
    await this.call(() => this.http.delete(EP.SALE_HELD_DELETE(id), this.withTimeout()));
    this.held = this.held.filter((h) => h.ref !== ref);
    this.heldCount = this.held.length;
    this.notify();
  }

  // ---- returns & exchange ----
  public async getPastSale(num: string): Promise<PastSale | null> {
    try {
      const r = await this.call(() => this.http.get(EP.SALES_LOOKUP, this.withTimeout({ params: { number: num.trim() } })));
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
  ): Promise<ProcessReturnResult> {
    const r = await this.postIdempotent(EP.SALE_RETURN(saleId), `return:${saleId}`, {
      items: this.groupReturnLines(items),
    });
    this.restock(items);
    void this.refreshShift();
    this.notify();
    return r.data.data;
  }

  /** cash+card = net difference due from customer (0 when return credit covers replacements). */
  public async processExchange(
    saleId: number,
    returnItems: { id: number; qty: number; return_to: 'on_hand' | 'damaged' }[],
    exchangeItems: { id: number; qty: number; unit_price?: number }[],
    netDiff: number,
    customerId: number | null = null,
    payment: ExchangePaymentOptions = {},
  ): Promise<ProcessReturnResult> {
    // F-15: never send a non-finite difference to the server.
    this.assertFiniteNumber(netDiff);
    const { cashCents, cardCents } = this.resolveExchangeTender(netDiff, payment);
    const body = {
      returned_items: this.groupReturnLines(returnItems),
      exchange_items: exchangeItems.map((i) => ({
        product_variant_id: i.id,
        qty: i.qty,
        ...(i.unit_price != null ? { unit_price: i.unit_price } : {}),
      })),
      cash_amount: toApi(cashCents),
      card_amount: toApi(cardCents),
    };
    const r = await this.postIdempotent(EP.SALE_EXCHANGE(saleId), `exchange:${saleId}`, body);
    this.restock(returnItems);
    this.deductLocalStock(exchangeItems);
    void this.refreshShift();
    this.notify();
    return r.data.data;
  }

  private resolveExchangeTender(
    netDiff: number,
    payment: ExchangePaymentOptions,
  ): { cashCents: number; cardCents: number } {
    const dueCents = Math.max(0, toCents(netDiff));
    if (dueCents === 0) {
      return { cashCents: 0, cardCents: 0 };
    }

    const method = payment.method ?? 'cash';
    if (method === 'card') {
      return { cashCents: 0, cardCents: dueCents };
    }
    if (method !== 'mixed') {
      return { cashCents: dueCents, cardCents: 0 };
    }

    const cashCents = toCents(payment.cashAmount ?? 0);
    if (cashCents <= 0 || cashCents >= dueCents) {
      throw new PosApiError(422, 'في الدفع المختلط، يجب تحديد جزء نقدي وجزء بالبطاقة', 'validation');
    }
    return { cashCents, cardCents: dueCents - cashCents };
  }

  // ---- transfers ----
  public async getTransfersIn(): Promise<StockTransfer[]> {
    const r = await this.call(() => this.http.get(EP.TRANSFERS_INCOMING, this.withTimeout()));
    this.transfersIn = r.data.data.map(mapTransfer);
    return this.transfersIn.map((t) => ({ ...t }));
  }

  /** `quantities` is keyed by the transfer line ID. */
  public async receiveTransfer(transferId: number, quantities: Record<number, number>): Promise<void> {
    const t = this.transfersIn.find((x) => x.id === transferId);
    if (!t) throw new PosApiError(404, 'التحويل غير موجود', 'not_found');
    const items = t.items.map((i) => ({ id: i.transferItemId, received_qty: quantities[i.transferItemId ?? i.id] ?? i.qty }));
    await this.postIdempotent(EP.TRANSFER_RECEIVE(transferId), `receive:${transferId}`, {
      items,
      expected_version: t.version,
    });
    this.transfersIn = this.transfersIn.filter((x) => x.id !== transferId);
    void this.loadCatalog(true).catch(() => undefined);
    this.notify();
  }

  public async getTransferSources(): Promise<TransferSource[]> {
    const r = await this.call(() => this.http.get(EP.TRANSFER_SOURCES, this.withTimeout()));
    return r.data.data.map(mapTransferSource);
  }

  public async requestTransfer(fromBranchId: number, items: { id: number; qty: number }[], notes?: string): Promise<string> {
    const r = await this.postIdempotent(EP.TRANSFER_REQUESTS, 'transfer', {
      from_branch_id: fromBranchId,
      items: items.map((i) => ({ product_variant_id: i.id, requested_qty: i.qty })),
      notes: notes ?? null,
    });
    this.notify();
    return r.data.data?.transfer_number ?? '';
  }

  // ---- stocktake ----
  /** No open session is reported as `session.id === 0` (screens render "لا توجد جلسة جرد مفتوحة"). */
  public async getStocktakeSession(): Promise<{ session: StocktakeSession; scope: PosProduct[] }> {
    const r = await this.call(() => this.http.get(EP.STOCKTAKES_CURRENT, this.withTimeout()));
    const d = r.data.data;
    if (!d) {
      this.stocktakeId = null;
      return { session: { id: 0, code: '', status: 'draft', counts: {}, log: [] }, scope: [] };
    }
    this.stocktakeId = d.id;
    // F-14: load the catalog first so local rows resolve; server-provided
    // names cover variants that are still missing (no silent filter drop).
    await this.loadCatalog().catch(() => undefined);
    const counts: Record<number, number> = {};
    for (const i of d.items ?? []) counts[Number(i.product_variant_id)] = Number(i.counted_qty ?? 0);
    const scope = this.mapStocktakeScope(d.items ?? []);
    // F-14: server-side scan history powers the log panel and last-scan card
    // on the real API, including after a page reload.
    const recentScans = (d.recent_scans ?? []) as any[];
    const first = recentScans[0];
    return {
      session: {
        id: d.id,
        code: d.stocktake_number,
        status: d.status,
        counts,
        log: this.mapStocktakeLog(recentScans),
        lastScan: first
          ? {
              id: Number(first.product_variant_id),
              qty: Number(first.qty ?? 0),
              via: 'barcode' as const,
              name: first.product_name ?? first.sku ?? undefined,
            }
          : undefined,
      },
      scope,
    };
  }

  private mapStocktakeScope(items: any[]): PosProduct[] {
    return items.map(
      (i): PosProduct =>
        this.byId.get(Number(i.product_variant_id)) ?? {
          id: Number(i.product_variant_id),
          sku: String(i.sku ?? ''),
          barcode: String(i.barcode ?? ''),
          name: String(i.product_name ?? i.sku ?? ''),
          cat: 'hand',
          price: 0,
          wholesale: 0,
          stock: 0,
          reorder: 0,
          h: 0,
        },
    );
  }

  private mapStocktakeLog(recentScans: any[]) {
    return recentScans.map((s) => ({
      id: Number(s.product_variant_id),
      add: Number(s.qty ?? 0),
      via: 'barcode' as const,
      t: this.formatScanTimeAr(s.created_at),
      name: s.product_name ?? s.sku ?? undefined,
    }));
  }

  private formatScanTimeAr(iso?: string): string {
    try {
      return new Date(iso ?? Date.now()).toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' });
    } catch {
      return '';
    }
  }

  public async recordStocktakeScan(
    barcodeOrVariantId: string | number,
    qty?: number,
    via: 'voice' | 'barcode' = 'barcode',
    words?: string,
    scanId: string = uuid(),
  ): Promise<{ duplicate: boolean; product_name?: string; counted_qty?: number }> {
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
      }, this.withTimeout()),
    );
    const payload = r.data?.data ?? r.data ?? {};
    const duplicate = Boolean(r.data?.duplicate ?? r.data?.data?.duplicate);
    if (!duplicate) this.notify();
    // F-14: display material comes straight from the scan response.
    return {
      duplicate,
      product_name: payload.product_name ?? undefined,
      counted_qty: payload.counted_qty != null ? Number(payload.counted_qty) : undefined,
    };
  }
}
