import type {
  Branch,
  CashierUser,
  Treasury,
  ProductCategory,
  PosProduct,
  Customer,
  PosCartLine,
  ShiftStats,
  PosShift,
  PosHeldSale,
  PastSale,
  StockTransfer,
  TransferSource,
  StocktakeSession,
  CreateSalePayload,
  PosSettings,
  ProcessReturnResult,
  QuoteResult,
} from '../types/pos.types';
import type { QuoteRequest } from './pos-api.core';
import { spreadDiscount, toCents } from '../lib/money';

const INITIAL_BRANCH: Branch = { id: 3, name: 'فرع مدينة نصر', code: 'NSR', is_active: true };

const INITIAL_CASHIER: CashierUser = {
  id: 21,
  name: 'أحمد سمير',
  initials: 'أس',
  role: 'كاشير أول',
  perms: ['pos.override_price', 'pos.discount', 'pos.sales.refund', 'transfers.create', 'transfers.receive', 'stocktakes.update'],
};

const INITIAL_TREASURIES: Treasury[] = [
  { id: 1, name: 'درج كاشير ١', code: 'DRW-01', state: 'free', last: 'آخر إغلاق أمس ١١:٤٠ م', balance: 500 },
  { id: 2, name: 'درج كاشير ٢', code: 'DRW-02', state: 'free', last: 'آخر إغلاق أمس ١٠:١٥ م', balance: 0 },
  { id: 4, name: 'درج الصالة', code: 'DRW-04', state: 'busy', last: 'مفتوح مع منى عادل', balance: 0 },
];

const INITIAL_CATS: ProductCategory[] = [
  { id: 'all', name: 'الكل' },
  { id: 'hand', name: 'شنط يد' },
  { id: 'cross', name: 'كروس' },
  { id: 'back', name: 'باك باك' },
  { id: 'wallet', name: 'محافظ' },
  { id: 'travel', name: 'سفر' },
  { id: 'acc', name: 'إكسسوارات' },
];

const INITIAL_PRODUCTS: PosProduct[] = [
  { id: 101, sku: 'HB-2041-BLK', barcode: '6221031204115', name: 'شنطة يد جلد كلاسيك — أسود', cat: 'hand', price: 450, wholesale: 390, stock: 14, reorder: 6, h: 30 },
  { id: 102, sku: 'HB-2041-CML', barcode: '6221031204122', name: 'شنطة يد جلد كلاسيك — جملي', cat: 'hand', price: 450, wholesale: 390, stock: 4, reorder: 6, h: 60 },
  { id: 103, sku: 'CB-1180-RED', barcode: '6221031118009', name: 'كروس صغيرة بسلسلة — أحمر', cat: 'cross', price: 285, wholesale: 240, stock: 22, reorder: 8, h: 18 },
  { id: 104, sku: 'CB-1180-BEG', barcode: '6221031118016', name: 'كروس صغيرة بسلسلة — بيج', cat: 'cross', price: 285, wholesale: 240, stock: 9, reorder: 8, h: 75 },
  { id: 105, sku: 'BP-3302-NVY', barcode: '6221031330241', name: 'باك باك لابتوب ١٥ بوصة — كحلي', cat: 'back', price: 620, wholesale: 540, stock: 7, reorder: 4, h: 255 },
  { id: 106, sku: 'BP-3305-GRY', barcode: '6221031330555', name: 'باك باك مدرسي مقاوم للمياه', cat: 'back', price: 380, wholesale: 320, stock: 0, reorder: 5, h: 220 },
  { id: 107, sku: 'WL-0712-BLK', barcode: '6221031071205', name: 'محفظة رجالي جلد طبيعي', cat: 'wallet', price: 195, wholesale: 160, stock: 41, reorder: 12, h: 40 },
  { id: 108, sku: 'WL-0820-PNK', barcode: '6221031082003', name: 'محفظة حريمي بسوستة — وردي', cat: 'wallet', price: 230, wholesale: 190, stock: 3, reorder: 10, h: 350 },
  { id: 109, sku: 'TR-5510-BLK', barcode: '6221031551004', name: 'شنطة سفر كابينة ٢٠ بوصة', cat: 'travel', price: 1450, wholesale: 1280, stock: 5, reorder: 3, h: 280 },
  { id: 110, sku: 'TR-5520-OLV', barcode: '6221031552001', name: 'حقيبة ويك إند قماش — زيتي', cat: 'travel', price: 760, wholesale: 660, stock: 11, reorder: 4, h: 110 },
  { id: 111, sku: 'AC-0091-GLD', barcode: '6221031009109', name: 'ميدالية مفاتيح معدن ذهبي', cat: 'acc', price: 65, wholesale: 50, stock: 88, reorder: 20, h: 85 },
  { id: 112, sku: 'AC-0105-STR', barcode: '6221031010501', name: 'حزام كتف بديل مطرز', cat: 'acc', price: 120, wholesale: 95, stock: 2, reorder: 10, h: 160 },
  { id: 113, sku: 'HB-2077-WHT', barcode: '6221031207703', name: 'توت باج كبيرة — أوف وايت', cat: 'hand', price: 540, wholesale: 470, stock: 16, reorder: 6, h: 95 },
  { id: 114, sku: 'CB-1215-BRN', barcode: '6221031121504', name: 'كروس رجالي جلد — بني', cat: 'cross', price: 340, wholesale: 290, stock: 6, reorder: 6, h: 45 },
  { id: 115, sku: 'WL-0901-BLU', barcode: '6221031090107', name: 'حافظة كروت RFID — أزرق', cat: 'wallet', price: 145, wholesale: 120, stock: 27, reorder: 10, h: 230 },
];

const INITIAL_CUSTOMERS: Customer[] = [
  { id: 15, name: 'مها فتحي', phone: '01012345678', tier: 'wholesale', points: 1240, status: 'Approved' },
  { id: 22, name: 'كريم عبد الله', phone: '01155550912', tier: 'retail', points: 320, status: 'Approved' },
  { id: 31, name: 'محلات النور للشنط', phone: '01228877410', tier: 'wholesale', points: 0, status: 'Pending' },
];

const INITIAL_HELD_SEED: PosHeldSale[] = [
  { ref: 'HLD-NSR-0042', at: '٠٢:١٤ م', mins: 18, customer: 'كريم عبد الله', note: 'زبون بيجيب محفظة إضافية', items: [{ id: 105, qty: 1 }, { id: 107, qty: 2 }] },
  { ref: 'HLD-NSR-0041', at: '١٢:٥٠ م', mins: 102, customer: null, note: 'بانتظار العميل', items: [{ id: 109, qty: 1 }] },
];

const INITIAL_PAST_SALES: Record<string, PastSale> = {
  'POS-NSR-10233': {
    id: 10233,
    number: 'POS-NSR-10233',
    date: '٣ نوفمبر ٢٠٢٦ — ٠٤:٢٢ م',
    cashier: 'منى عادل',
    customer: 'مها فتحي',
    method: 'نقدي',
    items: [
      { id: 101, qty: 2, price: 450 },
      { id: 107, qty: 1, price: 195 },
      { id: 111, qty: 3, price: 65 },
    ],
  },
};

const INITIAL_TRANSFERS_IN: StockTransfer[] = [
  {
    id: 881,
    number: 'TRF-2026-0881',
    from: 'المخزن الرئيسي — العبور',
    status: 'shipped',
    shipped: 'اليوم ٠٩:٣٠ ص',
    driver: 'محمود — ٠١٠٩٩٨٨٧٧٦',
    items: [{ id: 102, qty: 10 }, { id: 108, qty: 12 }, { id: 112, qty: 15 }, { id: 106, qty: 8 }],
  },
  {
    id: 874,
    number: 'TRF-2026-0874',
    from: 'فرع المعادي',
    status: 'shipped',
    shipped: 'أمس ٠٦:١٠ م',
    driver: 'عربية الفرع',
    items: [{ id: 104, qty: 5 }, { id: 114, qty: 4 }],
  },
];

const INITIAL_SOURCES: TransferSource[] = [
  { id: 1, name: 'المخزن الرئيسي — العبور', kind: 'warehouse' },
  { id: 2, name: 'فرع المعادي', kind: 'store' },
  { id: 5, name: 'فرع مصر الجديدة', kind: 'store' },
  { id: 6, name: 'فرع ٦ أكتوبر', kind: 'store' },
];

function delay(ms = 180): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

export class PosMockService {
  private branch: Branch = { ...INITIAL_BRANCH };
  private cashier: CashierUser = { ...INITIAL_CASHIER };
  private treasuries: Treasury[] = [...INITIAL_TREASURIES];
  private categories: ProductCategory[] = [...INITIAL_CATS];
  private products: PosProduct[] = [...INITIAL_PRODUCTS];
  private customers: Customer[] = [...INITIAL_CUSTOMERS];
  private heldSales: PosHeldSale[] = [...INITIAL_HELD_SEED];
  private pastSales: Record<string, PastSale> = { ...INITIAL_PAST_SALES };
  private transfersIn: StockTransfer[] = [...INITIAL_TRANSFERS_IN];
  private sources: TransferSource[] = [...INITIAL_SOURCES];

  private currentShift: PosShift | null = {
    id: 512,
    shift_number: 'SH-NSR-0512',
    status: 'open',
    treasury_id: 1,
    treasury: INITIAL_TREASURIES[0],
    branch_id: INITIAL_BRANCH.id,
    branch: INITIAL_BRANCH,
    cashier: INITIAL_CASHIER,
    opening_cash: 500,
    cash_sales_total: 7360,
    card_sales_total: 2840,
    cash_returns_total: 450,
    cash_expenses_total: 180,
    expected_closing_cash: 500 + 7360 - 450 - 180,
    opened_at: new Date(Date.now() - 4 * 60 * 60 * 1000),
    invoices_count: 23,
  };

  private stocktakeSession: StocktakeSession = {
    id: 19,
    code: 'STK-NSR-0019',
    status: 'in_progress',
    counts: { 101: 14, 103: 21, 107: 41, 111: 85 },
    lastScan: { id: 111, qty: 85, via: 'voice', words: 'خمسة وتمانين' },
    log: [
      { id: 111, add: 85, via: 'voice', words: 'خمسة وتمانين', t: '٠٣:٤٢' },
      { id: 107, add: 41, via: 'barcode', t: '٠٣:٣٨' },
      { id: 103, add: 21, via: 'barcode', t: '٠٣:٣١' },
      { id: 101, add: 14, via: 'barcode', t: '٠٣:٢٥' },
    ],
  };

  private listeners: Set<() => void> = new Set();
  /** F-18: idempotency replays + per-sale return accounting so QA sees API-like behavior. */
  private saleIdem = new Map<string, { order: PastSale; invoiceNumber: string }>();
  private returnedQty = new Map<number, Map<number, number>>();
  private refundedCash = new Map<number, number>();
  private refundedCard = new Map<number, number>();

  public subscribe(cb: () => void): () => void {
    this.listeners.add(cb);
    return () => this.listeners.delete(cb);
  }

  private notify(): void {
    this.listeners.forEach((cb) => cb());
  }

  // Branch & Cashier
  public syncActor(actor?: {
    id?: number;
    name?: string;
    roles?: string[];
    permissions?: string[];
    branch_id?: number | null;
    branch?: { id: number; name: string; code?: string } | null;
  } | null): void {
    if (!actor || !actor.name) return;
    const words = actor.name.trim().split(/\s+/);
    const initials = words.length >= 2
      ? `${words[0][0] ?? ''}${words[1][0] ?? ''}`
      : (actor.name.trim().slice(0, 2) || 'أس');
    const firstRole = actor.roles?.[0] ?? '';
    const roleLabel = firstRole === 'cashier' || firstRole === 'كاشير'
      ? 'كاشير أول'
      : (firstRole || 'كاشير أول');

    this.cashier = {
      id: actor.id || this.cashier.id,
      name: actor.name,
      initials,
      role: roleLabel,
      perms: actor.permissions?.length ? actor.permissions : this.cashier.perms,
    };

    if (actor.branch?.name) {
      this.branch = {
        id: actor.branch.id || actor.branch_id || this.branch.id,
        name: actor.branch.name,
        code: actor.branch.code || this.branch.code,
        is_active: true,
      };
    }

    if (this.currentShift) {
      this.currentShift.cashier = { ...this.cashier };
      this.currentShift.branch = { ...this.branch };
      this.currentShift.branch_id = this.branch.id;
    }
    this.notify();
  }

  public async getBranch(): Promise<Branch> {
    await delay(50);
    return { ...this.branch };
  }

  public async getCashier(): Promise<CashierUser> {
    await delay(50);
    return { ...this.cashier };
  }

  // Shift API
  public async getTreasuries(): Promise<Treasury[]> {
    await delay(120);
    return this.treasuries.map((t) => ({ ...t }));
  }

  public async getCurrentShift(): Promise<PosShift | null> {
    await delay(100);
    return this.currentShift ? { ...this.currentShift } : null;
  }

  public async openShift(treasuryId: number, openingCash: number): Promise<PosShift> {
    await delay(300);
    const treasury = this.treasuries.find((t) => t.id === treasuryId) || this.treasuries[0];
    treasury.state = 'busy';

    const newShift: PosShift = {
      id: Math.floor(Math.random() * 900) + 100,
      shift_number: `SH-NSR-${String(Math.floor(Math.random() * 900) + 100).padStart(4, '0')}`,
      status: 'open',
      treasury_id: treasury.id,
      treasury: { ...treasury },
      branch_id: this.branch.id,
      branch: { ...this.branch },
      cashier: { ...this.cashier },
      opening_cash: openingCash,
      cash_sales_total: 0,
      card_sales_total: 0,
      cash_returns_total: 0,
      cash_expenses_total: 0,
      expected_closing_cash: openingCash,
      opened_at: new Date(),
      invoices_count: 0,
    };

    this.currentShift = newShift;
    this.notify();
    return { ...newShift };
  }

  public async closeShift(actualClosingCash: number, notes?: string): Promise<PosShift> {
    await delay(350);
    if (!this.currentShift) throw new Error('لا توجد وردية مفتوحة للإغلاق');

    const expected =
      this.currentShift.opening_cash +
      (this.currentShift.cash_sales_total ?? 0) -
      (this.currentShift.cash_returns_total ?? 0) -
      (this.currentShift.cash_expenses_total ?? 0);

    this.currentShift.status = 'pending_approval';
    this.currentShift.closed_at = new Date();
    this.currentShift.actual_closing_cash = actualClosingCash;
    this.currentShift.expected_closing_cash = expected;
    this.currentShift.cash_difference = actualClosingCash - expected;
    this.currentShift.notes = notes || null;

    const treasury = this.treasuries.find((t) => t.id === this.currentShift?.treasury_id);
    if (treasury) {
      treasury.state = 'free';
      treasury.last = `آخر إغلاق اليوم ${new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' })}`;
    }

    const closed = { ...this.currentShift };
    this.currentShift = null;
    this.notify();
    return closed;
  }

  public async getShiftStats(): Promise<ShiftStats> {
    await delay(80);
    if (!this.currentShift) {
      return { cashSales: 0, cardSales: 0, returns: 0, expenses: 0, invoices: 0 };
    }
    return {
      cashSales: this.currentShift.cash_sales_total ?? 0,
      cardSales: this.currentShift.card_sales_total ?? 0,
      returns: this.currentShift.cash_returns_total ?? 0,
      expenses: this.currentShift.cash_expenses_total ?? 0,
      invoices: this.currentShift.invoices_count ?? 0,
    };
  }

  // Catalog & Products
  public async getCategories(): Promise<ProductCategory[]> {
    await delay(60);
    return [...this.categories];
  }

  public async getProducts(cat = 'all', query = '', lowStockOnly = false): Promise<PosProduct[]> {
    await delay(120);
    const q = query.trim().toLowerCase();
    return this.products.filter((p) => {
      const matchCat = cat === 'all' || p.cat === cat;
      const matchQ = !q || p.name.includes(q) || p.sku.toLowerCase().includes(q) || p.barcode.includes(q);
      const matchLow = !lowStockOnly || p.stock <= p.reorder;
      return matchCat && matchQ && matchLow;
    });
  }

  public getProductById(id: number): PosProduct | undefined {
    return this.products.find((p) => p.id === id);
  }

  // Customers
  public async getCustomers(query = '', _signal?: AbortSignal): Promise<Customer[]> {
    await delay(80);
    const q = query.trim();
    if (!q) return [...this.customers];
    return this.customers.filter((c) => c.phone.includes(q) || c.name.includes(q));
  }

  public async createQuickCustomer(data: { name: string; phone: string; tier: 'retail' | 'wholesale' }): Promise<Customer> {
    await delay(200);
    const newCust: Customer = {
      id: Math.floor(Math.random() * 900) + 100,
      name: data.name,
      phone: data.phone,
      tier: data.tier,
      points: 0,
      status: data.tier === 'wholesale' ? 'Pending' : 'Approved',
    };
    this.customers.push(newCust);
    this.notify();
    return { ...newCust };
  }

  // Sales & Checkout
  public async createSale(
    payload: CreateSalePayload,
    _idempotencyKey?: string,
  ): Promise<{ order: PastSale; invoiceNumber: string }> {
    // F-18: honor the idempotency key like the server — retries replay the
    // same invoice instead of double-deducting stock and double-selling.
    if (_idempotencyKey && this.saleIdem.has(_idempotencyKey)) {
      const stored = this.saleIdem.get(_idempotencyKey)!;
      return { order: { ...stored.order }, invoiceNumber: stored.invoiceNumber };
    }
    await delay(350);

    const invoiceNum = `POS-NSR-${Math.floor(Math.random() * 9000) + 10000}`;

    // Deduct stock
    for (const item of payload.items) {
      const product = this.products.find((p) => p.id === item.product_variant_id);
      if (product) {
        product.stock = Math.max(0, product.stock - item.qty);
      }
    }

    // Update active shift stats
    if (this.currentShift) {
      this.currentShift.cash_sales_total = (this.currentShift.cash_sales_total ?? 0) + (payload.cash_amount || 0);
      this.currentShift.card_sales_total = (this.currentShift.card_sales_total ?? 0) + (payload.card_amount || 0);
      this.currentShift.invoices_count = (this.currentShift.invoices_count || 0) + 1;
      this.currentShift.expected_closing_cash =
        this.currentShift.opening_cash +
        this.currentShift.cash_sales_total -
        (this.currentShift.cash_returns_total ?? 0) -
        (this.currentShift.cash_expenses_total ?? 0);
    }

    // Record past sale
    const customer = payload.customer_id ? this.customers.find((c) => c.id === payload.customer_id) : null;
    const saleOrder: PastSale = {
      id: Math.floor(Math.random() * 90000) + 10000,
      number: invoiceNum,
      date: new Date().toLocaleDateString('ar-EG', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
      }) + ` — ${new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' })}`,
      cashier: this.cashier.name,
      customer: customer ? customer.name : 'عميل نقدي',
      method: payload.payment_method === 'cash' ? 'نقدي' : payload.payment_method === 'card' ? 'بطاقة' : 'مختلط',
      cash_amount: payload.cash_amount ?? 0,
      card_amount: payload.card_amount ?? 0,
      items: payload.items.map((i) => ({
        id: i.product_variant_id,
        qty: i.qty,
        price: i.unit_price ?? this.products.find((p) => p.id === i.product_variant_id)?.price ?? 0,
      })),
    };

    this.pastSales[invoiceNum] = saleOrder;
    if (_idempotencyKey) this.saleIdem.set(_idempotencyKey, { order: { ...saleOrder }, invoiceNumber: invoiceNum });
    this.notify();
    return { order: saleOrder, invoiceNumber: invoiceNum };
  }

  // Held Sales
  public async getHeldSales(): Promise<PosHeldSale[]> {
    await delay(80);
    return [...this.heldSales];
  }

  public async holdSale(
    cart: PosCartLine[],
    customer: Customer | null,
    note: string,
    discountTotal = 0,
  ): Promise<PosHeldSale> {
    await delay(250);
    // Persist the effective unit_price and the cart-discount share per line so a
    // resume restores prices instead of silently dropping them.
    const shares = spreadDiscount(
      cart.map((c) => ({ id: c.id, totalC: toCents(c.price) * c.qty })),
      toCents(discountTotal),
    );
    const newHeld: PosHeldSale = {
      ref: `HLD-NSR-${String(Math.floor(Math.random() * 9000) + 1000).padStart(4, '0')}`,
      at: new Date().toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' }),
      mins: 1,
      customer: customer ? customer.name : null,
      customerObj: customer ? { ...customer } : null,
      note,
      items: cart.map((c) => ({
        id: c.id,
        qty: c.qty,
        unit_price: c.price,
        discount_amount: (shares.find((s) => s.id === c.id)?.d ?? 0) / 100,
        // F-03 parity with the real API: the manual flag round-trips.
        manual_price: Boolean(c.manualPrice),
      })),
    };
    this.heldSales.unshift(newHeld);
    this.notify();
    return { ...newHeld };
  }

  public async deleteHeldSale(ref: string): Promise<void> {
    await delay(120);
    this.heldSales = this.heldSales.filter((h) => h.ref !== ref);
    this.notify();
  }

  // Past sales & Returns
  public async getPastSale(num: string): Promise<PastSale | null> {
    await delay(150);
    const key = num.trim().toUpperCase();
    return this.pastSales[key] || this.pastSales[`POS-NSR-${key}`] || null;
  }

  public async processReturn(
    saleId: number,
    items: { id: number; qty: number; return_to: 'on_hand' | 'damaged' }[]
  ): Promise<ProcessReturnResult> {
    await delay(350);
    // F-18: mirror the server — unknown sales 404, per-line returnable caps
    // from the ORIGINAL price, and cash capped at cash actually paid.
    const sale = Object.values(this.pastSales).find((s) => s.id === saleId);
    if (!sale) throw new Error('الفاتورة غير موجودة');
    const pool = this.returnedQty.get(saleId) ?? new Map<number, number>();
    let totalRefund = 0;
    for (const item of items) {
      const sold = sale.items.find((i) => i.id === item.id);
      if (!sold) throw new Error(`الصنف ${item.id} غير موجود في الطلب الأصلي.`);
      const allowed = Math.max(0, sold.qty - (pool.get(item.id) ?? 0));
      if (item.qty > allowed + 1e-9) {
        throw new Error(`كمية المرتجع (${item.qty}) تتجاوز المتبقي القابل للإرجاع (${allowed}).`);
      }
      pool.set(item.id, (pool.get(item.id) ?? 0) + item.qty);
      const prod = this.products.find((p) => p.id === item.id);
      if (prod && item.return_to === 'on_hand') {
        prod.stock += item.qty;
      }
      totalRefund += sold.price * item.qty;
    }
    this.returnedQty.set(saleId, pool);

    // F-01 parity: cash can never exceed what the (mock) sale collected.
    const cashCap = Math.max(0, (sale.cash_amount ?? 0) - (this.refundedCash.get(saleId) ?? 0));
    const cardCap = Math.max(0, (sale.card_amount ?? 0) - (this.refundedCard.get(saleId) ?? 0));
    const cardRefund = Math.min(cardCap, totalRefund);
    const cashRefund = Math.min(cashCap, totalRefund - cardRefund);
    const overflow = totalRefund - cardRefund - cashRefund;
    this.refundedCash.set(saleId, (this.refundedCash.get(saleId) ?? 0) + cashRefund);
    this.refundedCard.set(saleId, (this.refundedCard.get(saleId) ?? 0) + cardRefund + overflow);

    if (this.currentShift) {
      this.currentShift.cash_returns_total = (this.currentShift.cash_returns_total ?? 0) + cashRefund;
      this.currentShift.expected_closing_cash =
        this.currentShift.opening_cash +
        (this.currentShift.cash_sales_total ?? 0) -
        this.currentShift.cash_returns_total -
        (this.currentShift.cash_expenses_total ?? 0);
    }
    this.notify();

    return {
      return_order_id: Math.floor(Math.random() * 10000),
      exchange_order_id: null,
      refund_amount: totalRefund,
      cash_refund_amount: cashRefund,
      card_refund_amount: cardRefund + overflow,
      exchange_credit: 0,
    };
  }

  public async processExchange(
    saleId: number,
    returnItems: { id: number; qty: number; return_to: 'on_hand' | 'damaged' }[],
    exchangeItems: { id: number; qty: number }[],
    netDiff: number,
    _customerId?: number | null,
    _payment?: { method?: 'cash' | 'card' | 'mixed'; cashAmount?: number },
  ): Promise<ProcessReturnResult> {
    await delay(400);
    // F-18: mirror the server — returns consume the returnable pool at the
    // original price; the diff split honors the requested payment method.
    const sale = Object.values(this.pastSales).find((s) => s.id === saleId);
    if (!sale) throw new Error('الفاتورة غير موجودة');
    const pool = this.returnedQty.get(saleId) ?? new Map<number, number>();
    for (const item of returnItems) {
      const sold = sale.items.find((i) => i.id === item.id);
      if (!sold) throw new Error(`الصنف ${item.id} غير موجود في الطلب الأصلي.`);
      const allowed = Math.max(0, sold.qty - (pool.get(item.id) ?? 0));
      if (item.qty > allowed + 1e-9) {
        throw new Error(`كمية المرتجع (${item.qty}) تتجاوز المتبقي القابل للإرجاع (${allowed}).`);
      }
      pool.set(item.id, (pool.get(item.id) ?? 0) + item.qty);
      const prod = this.products.find((p) => p.id === item.id);
      if (prod && item.return_to === 'on_hand') {
        prod.stock += item.qty;
      }
    }
    this.returnedQty.set(saleId, pool);
    // Replacements
    for (const item of exchangeItems) {
      const prod = this.products.find((p) => p.id === item.id);
      if (prod) {
        prod.stock = Math.max(0, prod.stock - item.qty);
      }
    }
    // Financial diff honors the requested method (mixed splits cash first).
    const method = _payment?.method ?? 'cash';
    const due = Math.max(0, netDiff);
    let cashDiff = 0;
    let cardDiff = 0;
    if (due > 0) {
      if (method === 'card') {
        cardDiff = due;
      } else if (method === 'mixed') {
        cashDiff = Math.min(due, Math.max(0, _payment?.cashAmount ?? 0));
        cardDiff = due - cashDiff;
      } else {
        cashDiff = due;
      }
    }
    const cardRefund = Math.max(0, -netDiff);
    if (this.currentShift) {
      this.currentShift.cash_sales_total = (this.currentShift.cash_sales_total ?? 0) + cashDiff;
      this.currentShift.card_sales_total = (this.currentShift.card_sales_total ?? 0) + cardDiff;
      this.currentShift.cash_returns_total = (this.currentShift.cash_returns_total ?? 0) + Math.max(0, -netDiff);
      this.currentShift.expected_closing_cash =
        this.currentShift.opening_cash +
        (this.currentShift.cash_sales_total ?? 0) -
        this.currentShift.cash_returns_total -
        (this.currentShift.cash_expenses_total ?? 0);
    }
    this.notify();

    return {
      return_order_id: Math.floor(Math.random() * 10000),
      exchange_order_id: Math.floor(Math.random() * 10000),
      refund_amount: Math.max(0, -netDiff),
      // Negative diffs route to card (never drawer cash), like the server.
      cash_refund_amount: 0,
      card_refund_amount: cardRefund,
      exchange_credit: Math.max(0, netDiff),
    };
  }

  // Transfers
  public async getTransfersIn(): Promise<StockTransfer[]> {
    await delay(120);
    return [...this.transfersIn];
  }

  public async receiveTransfer(transferId: number, quantities: Record<number, number>): Promise<void> {
    await delay(350);
    const transfer = this.transfersIn.find((t) => t.id === transferId);
    if (!transfer) return;

    for (const item of transfer.items) {
      const receivedQty = quantities[item.transferItemId ?? item.id] ?? item.qty;
      const product = this.products.find((p) => p.id === item.id);
      if (product) {
        product.stock += receivedQty;
      }
    }

    transfer.status = 'received';
    this.transfersIn = this.transfersIn.filter((t) => t.id !== transferId);
    this.notify();
  }

  public async getStockPage(query: string, low: boolean, page: number) {
    const products = await this.getProducts('all', query, low);
    return {
      rows: products.slice((page - 1) * 20, page * 20).map((product) => ({
        id: product.id, product_variant_id: product.id,
        variant: { sku: product.sku, barcode: product.barcode, product_name_ar: product.name },
        location: null, available: product.stock, reorder_level: product.reorder,
        is_low_stock: product.reorder > 0 && product.stock <= product.reorder,
      })),
      lastPage: Math.max(1, Math.ceil(products.length / 20)),
    };
  }

  public async getTransferSources(): Promise<TransferSource[]> {
    await delay(60);
    return [...this.sources];
  }

  public async requestTransfer(
    fromBranchId: number,
    items: { id: number; qty: number }[],
    notes?: string
  ): Promise<string> {
    await delay(300);
    const transferNum = `TRF-REQ-${Math.floor(Math.random() * 9000) + 1000}`;
    this.notify();
    return transferNum;
  }

  // Stocktake
  public async getStocktakeSession(): Promise<{ session: StocktakeSession; scope: PosProduct[] }> {
    await delay(100);
    const scope = this.products.slice(0, 12);
    return {
      session: {
        ...this.stocktakeSession,
        counts: { ...this.stocktakeSession.counts },
        log: [...this.stocktakeSession.log],
      },
      scope,
    };
  }

  public async recordStocktakeScan(
    barcodeOrVariantId: string | number,
    qty: number = 1,
    via: 'voice' | 'barcode' = 'barcode',
    words?: string,
    _scanId?: string
  ): Promise<{ duplicate: boolean; product_name?: string; counted_qty?: number }> {
    await delay(100);
    const variantId =
      typeof barcodeOrVariantId === 'number'
        ? barcodeOrVariantId
        : (this.products.find((p) => p.barcode === barcodeOrVariantId)?.id ?? 0);
    const prevCount = this.stocktakeSession.counts[variantId] || 0;
    const newCount = prevCount + qty;
    this.stocktakeSession.counts[variantId] = newCount;
    this.stocktakeSession.lastScan = {
      id: variantId,
      qty: newCount,
      via,
      words,
    };
    this.stocktakeSession.log.unshift({
      id: variantId,
      add: qty,
      via,
      words,
      t: new Date()
        .toLocaleTimeString('ar-EG', { hour: '2-digit', minute: '2-digit' })
        .replace(/\s?[صم]$/, ''),
    });
    if (this.stocktakeSession.log.length > 10) {
      this.stocktakeSession.log = this.stocktakeSession.log.slice(0, 10);
    }
    this.notify();
    const scanned = this.products.find((p) => p.id === variantId);
    return { duplicate: false, product_name: scanned?.name, counted_qty: newCount };
  }

  // ---- API-parity helpers (so VITE_POS_SOURCE=mock exposes the same surface as the API service) ----
  public async bootstrap(): Promise<void> {
    await delay(50);
    this.notify();
  }

  public async loadCatalog(_force = false): Promise<void> {
    await delay(10);
  }

  public getSettings(): PosSettings {
    return { max_discount_percent: 20, currency: 'EGP' };
  }

  public getHeldCount(): number {
    return this.heldSales.length;
  }

  public async lookupByBarcode(code: string): Promise<PosProduct | null> {
    const c = code.trim().toLowerCase();
    return this.products.find((p) => p.barcode === c || p.sku.toLowerCase() === c) ?? null;
  }

  public async quoteSale(req: QuoteRequest, _signal?: AbortSignal): Promise<QuoteResult> {
    await delay(40);
    const lines = req.items.map((i) => {
      const p = this.products.find((x) => x.id === i.product_variant_id);
      const unit = req.override_price && i.unit_price != null ? i.unit_price : (p?.price ?? 0);
      const disc = i.discount_amount ?? 0;
      return {
        product_variant_id: i.product_variant_id,
        qty: i.qty,
        unit_price: unit,
        discount_amount: disc,
        line_total: unit * i.qty - disc,
        available: p?.stock ?? 0,
        error: p && i.qty > p.stock ? `الكمية المطلوبة غير متوفرة في المخزون (المتاح: ${p.stock})` : null,
      };
    });
    const subtotal = lines.reduce((s, l) => s + l.unit_price * l.qty, 0);
    const discount_total = lines.reduce((s, l) => s + l.discount_amount, 0);
    return { lines, subtotal, discount_total, grand_total: subtotal - discount_total };
  }

  public async resumeHeldSale(ref: string): Promise<PosHeldSale> {
    await delay(120);
    const h = this.heldSales.find((x) => x.ref === ref);
    if (!h) throw new Error('الفاتورة المعلقة غير موجودة');
    // F-04 parity with the real API: the row stays until checkout or delete.
    this.notify();
    return { ...h };
  }

  /** F-04 parity with the real API: drop locally-held rows the checkout consumed. */
  public forgetHeldSaleRefs(serverIds: number[]): void {
    if (serverIds.length === 0) return;
    const gone = new Set(serverIds);
    this.heldSales = this.heldSales.filter((h) => !(h.serverId != null && gone.has(h.serverId)));
    this.notify();
  }
}

export const posMockService = new PosMockService();
