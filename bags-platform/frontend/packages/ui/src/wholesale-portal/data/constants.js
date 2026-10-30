/* Status maps + labels for the portal — keys follow backend enums. */

export const APP_STATUS = {
  pending: { label: 'قيد المراجعة', v: 'warning', icon: 'fa-hourglass-half' },
  approved: { label: 'معتمد', v: 'success', icon: 'fa-circle-check' },
  rejected: { label: 'مرفوض', v: 'danger', icon: 'fa-circle-xmark' },
  suspended: { label: 'موقوف', v: 'default', icon: 'fa-circle-pause' },
};

export const DOC_STATUS = { approved: ['مقبول', 'success'], rejected: ['مرفوض', 'danger'], pending: ['قيد المراجعة', 'warning'] };

export const ORDER_STATUS = {
  pending: ['قيد المراجعة', 'warning'], confirmed: ['مؤكد', 'info'], processing: ['جاري التجهيز', 'info'],
  shipped: ['تم الشحن', 'accent'], delivered: ['تم التسليم', 'success'], cancelled: ['ملغي', 'default'], returned: ['مرتجع', 'danger'],
};

export const PAY_METHOD = { credit: 'آجل', cod: 'الدفع عند الاستلام', bank_transfer: 'تحويل بنكي', wallet: 'المحفظة', card: 'بطاقة' };

export const PAY_STATUS = {
  paid: ['مدفوع', 'success'], unpaid: ['غير مدفوع', 'danger'], partial: ['مدفوع جزئيًا', 'warning'],
  pending: ['في انتظار التحصيل', 'default'], failed: ['فشل الدفع', 'danger'], refunded: ['مسترد', 'info'],
};

export const QUOTE_STATUS = {
  draft: ['مسودة', 'default'], requested: ['مطلوب تسعيره', 'warning'], priced: ['تم تسعيره من الإدارة', 'info'],
  accepted: ['مقبول', 'success'], rejected: ['مرفوض', 'danger'], expired: ['منتهي', 'default'], converted: ['محوّل لطلب', 'accent'],
};

export const NOTICE_STATUS = { pending: ['قيد المراجعة', 'warning'], approved: ['معتمد', 'success'], rejected: ['مرفوض', 'danger'] };

export const RETURN_STATUS = {
  requested: ['تم تقديم الطلب', 'warning'], approved: ['تمت الموافقة', 'info'], received: ['تم استلام المرتجع', 'accent'],
  refunded: ['تم رد المبلغ', 'success'], rejected: ['مرفوض', 'danger'], cancelled: ['ملغي', 'default'],
};

export const NOTICE_METHOD = { bank_transfer: 'تحويل بنكي', check: 'شيك', deposit: 'إيداع' };

export const REFUND_METHOD = { original_payment: 'نفس وسيلة الدفع', wallet: 'رصيد المحفظة', bank_transfer: 'تحويل بنكي', cash: 'نقدي' };

export const DOC_TYPE = { commercial_register: 'السجل التجاري', tax_card: 'البطاقة الضريبية', national_id: 'البطاقة الشخصية', other: 'مستند آخر' };

export const WALLET_TYPE = { checkout_payment: 'دفع طلب', refund: 'استرداد مرتجع', adjustment: 'تسوية إدارية', order_reversal: 'إلغاء طلب' };

export const RETURN_SKIP_REASON = { not_found_or_inactive: 'الصنف غير موجود أو غير متاح', insufficient_stock: 'الكمية غير متاحة' };
