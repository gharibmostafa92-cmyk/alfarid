import React from 'react';
import { AlertTriangle, Trash2, X } from 'lucide-react';
import { Button } from '@alfarid/ui';

export interface ConfirmModalProps {
  open: boolean;
  onClose: () => void;
  onConfirm?: () => void;
  title: string;
  text: string;
  confirmLabel?: string;
  cancelLabel?: string;
  tone?: 'danger' | 'warn';
  rejectWith?: React.ReactNode;
}

export function ConfirmModal({
  open,
  onClose,
  onConfirm,
  title,
  text,
  confirmLabel = 'تأكيد الحذف',
  cancelLabel = 'إلغاء',
  tone = 'danger',
  rejectWith,
}: ConfirmModalProps) {
  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" dir="rtl">
      <div className="fixed inset-0 bg-black/50 backdrop-blur-sm" onClick={onClose} />
      <div className="relative w-full max-w-md bg-white rounded-2xl shadow-xl border border-zinc-200 z-10 overflow-hidden">
        <div className="p-6">
          <div className="flex items-start gap-4">
            <div
              className={`w-12 h-12 rounded-xl flex items-center justify-center shrink-0 ${
                tone === 'danger' ? 'bg-red-50 text-red-600' : 'bg-amber-50 text-amber-600'
              }`}
            >
              {tone === 'danger' ? (
                <Trash2 className="w-6 h-6" />
              ) : (
                <AlertTriangle className="w-6 h-6" />
              )}
            </div>
            <div className="flex-1">
              <h3 className="text-base font-bold text-zinc-900">{title}</h3>
              <p className="text-xs text-zinc-500 mt-1 leading-relaxed">{text}</p>
            </div>
            <button
              onClick={onClose}
              className="text-zinc-400 hover:text-zinc-600 p-1"
            >
              <X className="w-4 h-4" />
            </button>
          </div>

          {rejectWith && (
            <div className="mt-4 p-3.5 bg-red-50/80 border border-red-200 rounded-xl text-xs text-red-700 leading-relaxed">
              {rejectWith}
            </div>
          )}
        </div>

        <div className="border-t border-zinc-100 bg-zinc-50/70 px-6 py-3.5 flex items-center justify-end gap-2.5">
          <Button variant="outline" size="sm" onClick={onClose}>
            {cancelLabel}
          </Button>
          {!rejectWith && (
            <Button
              variant={tone === 'danger' ? 'danger' : 'primary'}
              size="sm"
              onClick={() => {
                if (onConfirm) onConfirm();
                onClose();
              }}
            >
              {confirmLabel}
            </Button>
          )}
        </div>
      </div>
    </div>
  );
}
