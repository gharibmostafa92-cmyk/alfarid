import React, { useState, useEffect, useRef } from 'react';
import { usePosCartStore } from '../stores/pos-cart.store';
import { usePosShiftStore } from '../stores/pos-shift.store';
import { Button, Input, Modal, Badge } from '@alfarid/ui';
import { useBranding } from '@alfarid/ui/branding';
import { formatCurrency } from '@alfarid/utils';
import { Barcode, Trash2, Plus, Minus, CreditCard, Banknote, ShoppingCart, Clock } from 'lucide-react';

export function CashierScreen() {
  const { branding } = useBranding();
  const { currentShift } = usePosShiftStore();
  const { items, addItem, updateQuantity, removeItem, clearCart, total, subtotal } = usePosCartStore();
  const [barcodeInput, setBarcodeInput] = useState('');
  const [isPaymentOpen, setIsPaymentOpen] = useState(false);
  const barcodeInputRef = useRef<HTMLInputElement>(null);

  // Sample quick products for the cashier touch/click layout
  const quickProducts = [
    { id: 101, barcode: '6221001', name: 'شنطة يد كلاسيك جلد أسود', price: 650 },
    { id: 102, barcode: '6221002', name: 'شنطة كروس نسائي بيج', price: 420 },
    { id: 103, barcode: '6221003', name: 'محفظة رجالي جلد طبيعي', price: 180 },
    { id: 104, barcode: '6221004', name: 'شنطة ظهر سفر وتربروف', price: 850 },
    { id: 105, barcode: '6221005', name: 'حزام جلد طبيعي بني', price: 150 },
    { id: 106, barcode: '6221006', name: 'حقيبة لابتوب 15.6 بوصة', price: 550 },
  ];

  useEffect(() => {
    barcodeInputRef.current?.focus();
  }, []);

  const handleBarcodeSubmit = (e: React.FormEvent) => {
    e.preventDefault();
    if (!barcodeInput.trim()) return;
    const found = quickProducts.find((p) => p.barcode === barcodeInput.trim());
    if (found) {
      addItem(found);
    } else {
      addItem({
        id: Date.now(),
        barcode: barcodeInput.trim(),
        name: `صنف باركود (${barcodeInput.trim()})`,
        price: 250,
      });
    }
    setBarcodeInput('');
  };

  return (
    <div className="flex h-screen w-screen overflow-hidden bg-zinc-100 font-sans" dir="rtl">
      {/* Left/Main Side: Product Catalogue & Barcode Scanner */}
      <div className="flex-1 flex flex-col p-4 border-l border-zinc-200">
        {/* Top Shift Status Bar */}
        <div className="h-14 bg-white rounded-xl shadow-sm px-4 flex items-center justify-between mb-4 border border-zinc-200">
          <div className="flex items-center gap-4">
            <span dir="auto" className="font-bold text-zinc-800 text-lg truncate">{branding.name} POS</span>
            <span className="text-zinc-400">|</span>
            <span className="text-sm text-zinc-600 font-medium">{currentShift?.branchName}</span>
            <Badge variant="success">الوردية #{currentShift?.id} مفتوحة</Badge>
          </div>
          <div className="flex items-center gap-3 text-sm text-zinc-500">
            <Clock className="w-4 h-4" />
            <span>الكاشير: <strong>{currentShift?.cashierName}</strong></span>
          </div>
        </div>

        {/* Barcode scanner input */}
        <form onSubmit={handleBarcodeSubmit} className="mb-4">
          <div className="relative">
            <Barcode className="absolute right-3.5 top-3 w-5 h-5 text-zinc-400" />
            <input
              ref={barcodeInputRef}
              type="text"
              value={barcodeInput}
              onChange={(e) => setBarcodeInput(e.target.value)}
              placeholder="امسح الباركود أو اضغط Enter (F2 للتركيز)..."
              className="w-full h-11 pr-11 pl-4 rounded-xl border border-zinc-300 bg-white shadow-sm text-sm focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/20"
            />
          </div>
        </form>

        {/* Fast product grid */}
        <div className="flex-1 overflow-y-auto">
          <h3 className="text-xs font-semibold text-zinc-500 uppercase mb-3">الأصناف السريعة الأكثر مبيعاً</h3>
          <div className="grid grid-cols-2 md:grid-cols-3 gap-3">
            {quickProducts.map((product) => (
              <button
                key={product.id}
                onClick={() => addItem(product)}
                className="bg-white p-4 rounded-xl border border-zinc-200 shadow-sm hover:border-brand-500 hover:shadow transition-all text-right flex flex-col justify-between h-28"
              >
                <div>
                  <h4 className="font-semibold text-zinc-900 text-sm line-clamp-2">{product.name}</h4>
                  <span className="text-xs text-zinc-400 font-mono">{product.barcode}</span>
                </div>
                <div className="text-brand-600 font-bold text-base">{formatCurrency(product.price)}</div>
              </button>
            ))}
          </div>
        </div>
      </div>

      {/* Right Side: Cart, Totals & Quick Actions */}
      <div className="w-96 bg-white flex flex-col shadow-lg border-r border-zinc-200">
        <div className="p-4 border-b border-zinc-200 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <ShoppingCart className="w-5 h-5 text-brand-600" />
            <h2 className="font-bold text-zinc-800">فاتورة البيع الحالية</h2>
          </div>
          {items.length > 0 && (
            <button
              onClick={clearCart}
              className="text-xs text-red-600 hover:text-red-700 font-medium"
            >
              مسح السلة
            </button>
          )}
        </div>

        {/* Cart items list */}
        <div className="flex-1 overflow-y-auto p-4 space-y-3">
          {items.length === 0 ? (
            <div className="h-full flex flex-col items-center justify-center text-zinc-400 text-sm">
              <ShoppingCart className="w-12 h-12 stroke-1 mb-2 text-zinc-300" />
              <span>السلة فارغة</span>
              <span className="text-xs text-zinc-400 mt-1">امسح الباركود للبدء</span>
            </div>
          ) : (
            items.map((item) => (
              <div
                key={item.id}
                className="p-3 bg-zinc-50 rounded-xl border border-zinc-200 flex flex-col gap-2"
              >
                <div className="flex justify-between items-start">
                  <span className="font-medium text-sm text-zinc-800 leading-snug">{item.name}</span>
                  <button
                    onClick={() => removeItem(item.id)}
                    className="text-zinc-400 hover:text-red-500 transition-colors"
                  >
                    <Trash2 className="w-4 h-4" />
                  </button>
                </div>
                <div className="flex items-center justify-between text-xs text-zinc-500">
                  <span>{formatCurrency(item.price)} × {item.quantity}</span>
                  <span className="font-bold text-sm text-zinc-900">
                    {formatCurrency(item.price * item.quantity)}
                  </span>
                </div>
                <div className="flex items-center justify-end gap-2 mt-1">
                  <button
                    onClick={() => updateQuantity(item.id, -1)}
                    className="w-7 h-7 rounded-lg bg-white border border-zinc-300 flex items-center justify-center hover:bg-zinc-100"
                  >
                    <Minus className="w-3.5 h-3.5" />
                  </button>
                  <span className="font-bold text-sm w-6 text-center">{item.quantity}</span>
                  <button
                    onClick={() => updateQuantity(item.id, 1)}
                    className="w-7 h-7 rounded-lg bg-white border border-zinc-300 flex items-center justify-center hover:bg-zinc-100"
                  >
                    <Plus className="w-3.5 h-3.5" />
                  </button>
                </div>
              </div>
            ))
          )}
        </div>

        {/* Totals & Checkout Button */}
        <div className="p-4 border-t border-zinc-200 bg-zinc-50/70 space-y-3">
          <div className="flex justify-between text-sm text-zinc-600">
            <span>المجموع الفرعي:</span>
            <span>{formatCurrency(subtotal())}</span>
          </div>
          <div className="flex justify-between text-lg font-bold text-zinc-900 border-t border-zinc-200 pt-2">
            <span>الإجمالي النهائي:</span>
            <span className="text-brand-600">{formatCurrency(total())}</span>
          </div>

          <Button
            className="w-full h-12 text-base font-bold shadow-md"
            disabled={items.length === 0}
            onClick={() => setIsPaymentOpen(true)}
          >
            إتمام الدفع وطباعة الفاتورة (Space)
          </Button>
        </div>
      </div>

      {/* Payment Modal */}
      <Modal
        isOpen={isPaymentOpen}
        onClose={() => setIsPaymentOpen(false)}
        title="طريقة الدفع وإغلاق الفاتورة"
      >
        <div className="space-y-4">
          <div className="text-center py-4 bg-zinc-50 rounded-xl">
            <span className="text-xs text-zinc-500">المبلغ المطلوب سداده</span>
            <div className="text-3xl font-extrabold text-brand-600 mt-1">{formatCurrency(total())}</div>
          </div>

          <div className="grid grid-cols-2 gap-4">
            <button
              onClick={() => {
                clearCart();
                setIsPaymentOpen(false);
              }}
              className="p-4 border-2 border-brand-500 rounded-xl flex flex-col items-center justify-center gap-2 hover:bg-brand-50 transition-colors"
            >
              <Banknote className="w-8 h-8 text-brand-600" />
              <span className="font-bold text-sm">نقدي (كاش)</span>
            </button>
            <button
              onClick={() => {
                clearCart();
                setIsPaymentOpen(false);
              }}
              className="p-4 border-2 border-zinc-200 rounded-xl flex flex-col items-center justify-center gap-2 hover:bg-zinc-50 transition-colors"
            >
              <CreditCard className="w-8 h-8 text-zinc-600" />
              <span className="font-bold text-sm">فيزا / كارت</span>
            </button>
          </div>
        </div>
      </Modal>
    </div>
  );
}
