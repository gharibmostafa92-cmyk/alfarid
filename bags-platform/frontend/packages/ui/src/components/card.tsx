import React from 'react';
import { cn } from '../lib/utils';

export interface CardProps extends Omit<React.HTMLAttributes<HTMLDivElement>, 'title'> {
  title?: React.ReactNode;
  subtitle?: React.ReactNode;
  actions?: React.ReactNode;
}

export function Card({ className, title, subtitle, actions, children, ...props }: CardProps) {
  return (
    <div
      className={cn(
        'rounded-xl border border-zinc-200 bg-white shadow-sm transition-all text-right',
        className
      )}
      dir="rtl"
      {...props}
    >
      {(title || subtitle || actions) && (
        <div className="flex items-center justify-between border-b border-zinc-100 px-6 py-4">
          <div>
            {title && <h3 className="text-base font-semibold text-zinc-900">{title}</h3>}
            {subtitle && <p className="text-xs text-zinc-500 mt-0.5">{subtitle}</p>}
          </div>
          {actions && <div className="flex items-center gap-2">{actions}</div>}
        </div>
      )}
      <div className="p-6">{children}</div>
    </div>
  );
}
