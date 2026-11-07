export enum OrderStatus {
  Pending = 'pending',
  Confirmed = 'confirmed',
  Processing = 'processing',
  Shipped = 'shipped',
  Delivered = 'delivered',
  Cancelled = 'cancelled',
  Returned = 'returned',
}

export const OrderStatusLabelsAr: Record<string, string> = {
  [OrderStatus.Pending]: 'قيد المراجعة',
  [OrderStatus.Confirmed]: 'تم تأكيد الطلب',
  [OrderStatus.Processing]: 'جاري التجهيز',
  [OrderStatus.Shipped]: 'تم الشحن',
  out_for_delivery: 'مع المندوب',
  [OrderStatus.Delivered]: 'تم التوصيل',
  [OrderStatus.Cancelled]: 'ملغي',
  [OrderStatus.Returned]: 'مرتجع',
};

export enum PaymentMethod {
  Cod = 'cod',
  Wallet = 'wallet',
  BankTransfer = 'bank_transfer',
  Credit = 'credit',
}

export const PaymentMethodLabelsAr: Record<string, string> = {
  [PaymentMethod.Cod]: 'الدفع عند الاستلام',
  [PaymentMethod.Wallet]: 'محفظة المتجر',
  [PaymentMethod.BankTransfer]: 'تحويل عبر InstaPay',
  [PaymentMethod.Credit]: 'دفع آجل',
};

export enum PaymentStatus {
  Pending = 'pending',
  Paid = 'paid',
  Failed = 'failed',
  Refunded = 'refunded',
  PartiallyRefunded = 'partially_refunded',
}

export const PaymentStatusLabelsAr: Record<string, string> = {
  [PaymentStatus.Pending]: 'غير مدفوع',
  unpaid: 'غير مدفوع',
  [PaymentStatus.Paid]: 'تم الدفع',
  [PaymentStatus.Failed]: 'فشل الدفع',
  [PaymentStatus.Refunded]: 'تم رد المبلغ',
  [PaymentStatus.PartiallyRefunded]: 'مسترد جزئياً',
};

export enum ShiftStatus {
  Open = 'open',
  Closed = 'closed',
  Approved = 'approved',
}

export const ShiftStatusLabelsAr: Record<ShiftStatus, string> = {
  [ShiftStatus.Open]: 'مفتوحة',
  [ShiftStatus.Closed]: 'مغلقة',
  [ShiftStatus.Approved]: 'معتمدة',
};

export enum QuotationStatus {
  Pending = 'pending',
  Priced = 'priced',
  Accepted = 'accepted',
  Rejected = 'rejected',
  Converted = 'converted',
}

export const QuotationStatusLabelsAr: Record<QuotationStatus, string> = {
  [QuotationStatus.Pending]: 'بانتظار التسعير',
  [QuotationStatus.Priced]: 'تم التسعير',
  [QuotationStatus.Accepted]: 'مقبول',
  [QuotationStatus.Rejected]: 'مرفوض',
  [QuotationStatus.Converted]: 'تم التحويل لطلب',
};

export enum CustomerStatus {
  Pending = 'pending',
  Approved = 'approved',
  Rejected = 'rejected',
  Suspended = 'suspended',
}

export const CustomerStatusLabelsAr: Record<CustomerStatus, string> = {
  [CustomerStatus.Pending]: 'قيد المراجعة',
  [CustomerStatus.Approved]: 'معتمد',
  [CustomerStatus.Rejected]: 'مرفوض',
  [CustomerStatus.Suspended]: 'موقوف',
};

export enum CustomerType {
  Retail = 'retail',
  Wholesale = 'wholesale',
  Distributor = 'distributor',
}

export const CustomerTypeLabelsAr: Record<CustomerType, string> = {
  [CustomerType.Retail]: 'عميل قطاعي',
  [CustomerType.Wholesale]: 'تاجر جملة',
  [CustomerType.Distributor]: 'موزع',
};

export function getStatusLabel<T extends string>(
  labels: Record<T, string> | Record<string, string>,
  key: T | string | null | undefined,
  fallback = '-'
): string {
  if (!key) return fallback;
  const lookup = typeof key === 'string' ? key.toLowerCase().trim() : key;
  return (labels as Record<string, string>)[lookup] ?? (labels as Record<string, string>)[key] ?? key;
}
