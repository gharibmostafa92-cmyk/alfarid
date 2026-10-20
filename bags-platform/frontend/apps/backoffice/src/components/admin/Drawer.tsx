import React, { useEffect } from 'react';
import { X } from 'lucide-react';

export interface DrawerProps {
  open: boolean;
  onClose: () => void;
  title: React.ReactNode;
  crumbs?: React.ReactNode;
  children: React.ReactNode;
  footer?: React.ReactNode;
  dirty?: boolean;
  width?: string;
}

export function Drawer({
  open,
  onClose,
  title,
  crumbs,
  children,
  footer,
  dirty,
  width = 'max-w-xl',
}: DrawerProps) {
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && open) onClose();
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [open, onClose]);

  if (!open) return null;

  return (
    <div className="fixed inset-0 z-50 overflow-hidden" dir="rtl">
      {/* Backdrop */}
      <div
        className="fixed inset-0 bg-black/40 backdrop-blur-sm transition-opacity"
        onClick={onClose}
      />

      <div className="fixed inset-y-0 left-0 flex max-w-full">
        <div className={`relative w-screen ${width} bg-white shadow-2xl flex flex-col z-10 animate-in slide-in-from-left duration-200`}>
          {/* Header */}
          <div className="border-b border-zinc-100 px-6 py-4 flex items-center justify-between">
            <div>
              {crumbs && <div className="text-xs text-zinc-400 mb-1">{crumbs}</div>}
              <div className="flex items-center gap-2">
                <h3 className="text-lg font-bold text-zinc-900">{title}</h3>
                {dirty && (
                  <span className="text-[10px] bg-amber-50 text-amber-700 border border-amber-200 px-2 py-0.5 rounded-full font-medium">
                    غير محفوظ
                  </span>
                )}
              </div>
            </div>
            <button
              onClick={onClose}
              className="p-1.5 rounded-lg text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 transition-colors"
            >
              <X className="w-5 h-5" />
            </button>
          </div>

          {/* Body */}
          <div className="flex-1 overflow-y-auto p-6 space-y-6">{children}</div>

          {/* Footer */}
          {footer && (
            <div className="border-t border-zinc-100 bg-zinc-50/70 px-6 py-4 flex items-center gap-3">
              {footer}
            </div>
          )}
        </div>
      </div>
    </div>
  );
}
