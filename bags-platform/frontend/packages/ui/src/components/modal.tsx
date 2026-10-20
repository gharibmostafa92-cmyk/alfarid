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
    <div className="fixed inset-0 z-50 flex items-center justify-center p-4" dir="rtl">
      <div className="fixed inset-0 bg-black/50 backdrop-blur-sm transition-opacity" onClick={onClose} />
      <div
        className={cn(
          'relative w-full rounded-2xl bg-white shadow-xl transition-all z-10 overflow-hidden flex flex-col max-h-[90vh]',
          sizeClasses[size],
          className
        )}
      >
        {title && (
          <div className="flex items-center justify-between border-b border-zinc-100 px-6 py-4">
            <h3 className="text-lg font-semibold text-zinc-900">{title}</h3>
            <button
              onClick={onClose}
              className="rounded-lg p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 transition-colors"
            >
              <X className="h-5 w-5" />
            </button>
          </div>
        )}
        <div className="p-6 overflow-y-auto flex-1">{children}</div>
        {footer && (
          <div className="flex items-center justify-end gap-3 border-t border-zinc-100 bg-zinc-50/50 px-6 py-4">
            {footer}
          </div>
        )}
      </div>
    </div>
  );
}
