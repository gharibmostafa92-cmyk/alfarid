import type { PosCartLine, Customer, Branch, CashierUser } from '../types/pos.types';

export interface ArchivedReceipt {
  invoiceNumber: string;
  total: number;
  method: 'cash' | 'card' | 'mixed';
  received: number;
  change: number;
  cashA: number;
  cardA: number;
  cart: PosCartLine[];
  customer: Customer | null;
  discount: number;
  notes?: string;
  branch?: Branch | null;
  cashier?: CashierUser | null;
  timestamp: number;
}

const MAX_RECEIPTS = 5;
const archive: ArchivedReceipt[] = [];

export function pushReceipt(receipt: ArchivedReceipt): void {
  archive.unshift(receipt);
  if (archive.length > MAX_RECEIPTS) {
    archive.length = MAX_RECEIPTS;
  }
}

export function getLastReceipt(): ArchivedReceipt | null {
  return archive[0] ?? null;
}

export function getReceiptArchive(): ArchivedReceipt[] {
  return [...archive];
}

export function clearReceiptArchive(): void {
  archive.length = 0;
}
