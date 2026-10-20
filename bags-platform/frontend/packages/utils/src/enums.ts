export enum OrderStatus {
  Pending = 'pending',
  Confirmed = 'confirmed',
  Processing = 'processing',
  Shipped = 'shipped',
  Delivered = 'delivered',
  Cancelled = 'cancelled',
  Returned = 'returned',
}

export const OrderStatusLabelsAr: Record<OrderStatus, string> = {
  [OrderStatus.Pending]: 'قيد الانتظار',
  [OrderStatus.Confirmed]: 'مؤكد',
  [OrderStatus.Processing]: 'جاري التجهيز',
  [OrderStatus.Shipped]: 'تم الشحن',
  [OrderStatus.Delivered]: 'تم التوصيل',
  [OrderStatus.Cancelled]: 'ملغي',
  [OrderStatus.Returned]: 'مرتجع',
};

export enum PaymentStatus {
  Pending = 'pending',
  Paid = 'paid',
  Failed = 'failed',
  Refunded = 'refunded',
  PartiallyRefunded = 'partially_refunded',
}

export const PaymentStatusLabelsAr: Record<PaymentStatus, string> = {
  [PaymentStatus.Pending]: 'قيد السداد',
  [PaymentStatus.Paid]: 'مدفوع',
  [PaymentStatus.Failed]: 'فشل الدفع',
  [PaymentStatus.Refunded]: 'مسترد',
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
