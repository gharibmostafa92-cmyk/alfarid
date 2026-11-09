'use client';

import React, { useEffect } from 'react';
import { X } from 'lucide-react';
import { cn } from '../lib/utils';

export interface ModalProps {
  isOpen: boolean;
  onClose: () => void;
  title?: React.ReactNode;
  children: React.ReactNode;
  footer?: React.ReactNode;
  className?: string;
  size?: 'sm' | 'md' | 'lg' | 'xl';
}

export function Modal({
  isOpen,
  onClose,
  title,
  children,
  footer,
  className,
  size = 'md',
}: ModalProps) {
  useEffect(() => {
    const handleKeyDown = (e: KeyboardEvent) => {
      if (e.key === 'Escape' && isOpen) {
        onClose();
      }
    };
    window.addEventListener('keydown', handleKeyDown);
    return () => window.removeEventListener('keydown', handleKeyDown);
  }, [isOpen, onClose]);

  if (!isOpen) return null;

  const sizeClasses = {
    sm: 'max-w-md',
    md: 'max-w-lg',
    lg: 'max-w-2xl',
    xl: 'max-w-4xl',
  };

  return (
    <div className="fixed inset-0 z-50 flex items-end sm:items-center justify-center p-0 sm:p-4" dir="rtl">
      <div className="fixed inset-0 bg-black/50 backdrop-blur-sm transition-opacity duration-200" onClick={onClose} />
      <div
        className={cn(
          'relative w-full rounded-t-3xl sm:rounded-2xl bg-white shadow-xl z-10 overflow-hidden flex flex-col max-h-[90dvh] pb-[calc(1rem+env(safe-area-inset-bottom,0px))] sm:pb-0 transition-transform duration-200 ease-out',
          sizeClasses[size],
          className
        )}
      >
        <div className="sm:hidden w-10 h-1 bg-zinc-300 rounded-full mx-auto mt-3 mb-1 shrink-0" aria-hidden="true" />
        {title && (
          <div className="flex items-center justify-between border-b border-zinc-100 px-6 py-4">
            <h3 className="text-lg font-semibold text-zinc-900 truncate">{title}</h3>
            <button
              onClick={onClose}
              className="rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 active:scale-95 transition-all [touch-action:manipulation] shrink-0"
              aria-label="إغلاق"
            >
              <X className="h-5 w-5" />
            </button>
          </div>
        )}
        <div className="p-6 overflow-y-auto flex-1 overscroll-contain">{children}</div>
        {footer && (
          <div className="flex items-center justify-end gap-3 border-t border-zinc-100 bg-zinc-50/50 px-6 py-4 shrink-0">
            {footer}
          </div>
        )}
      </div>
    </div>
  );
}
