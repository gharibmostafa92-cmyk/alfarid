import React from 'react';
import { Link } from 'react-router-dom';
import { ChevronLeft } from 'lucide-react';

export interface PageHeaderProps {
  crumbs?: [label: string, path?: string][];
  light?: React.ReactNode;
  bold: React.ReactNode;
  sub?: React.ReactNode;
  actions?: React.ReactNode;
  decor?: string;
}

export function PageHeader({ crumbs = [], light, bold, sub, actions, decor }: PageHeaderProps) {
  return (
    <div className="mb-6 bg-white border border-zinc-200/80 rounded-2xl p-6 shadow-sm flex flex-col md:flex-row md:items-center justify-between gap-4 relative overflow-hidden">
      <div className="space-y-1.5 z-10">
        {crumbs.length > 0 && (
          <nav className="flex items-center gap-1.5 text-xs text-zinc-500 mb-2 font-medium">
            {crumbs.map(([label, path], idx) => {
              const isLast = idx === crumbs.length - 1;
              return (
                <React.Fragment key={idx}>
                  {idx > 0 && <ChevronLeft className="w-3.5 h-3.5 text-zinc-400" />}
                  {path && !isLast ? (
                    <Link to={path} className="hover:text-brand-600 transition-colors">
                      {label}
                    </Link>
                  ) : (
                    <span className={isLast ? 'text-zinc-900 font-semibold' : ''}>{label}</span>
                  )}
                </React.Fragment>
              );
            })}
          </nav>
        )}
        <div className="flex items-baseline gap-2">
          {light && <span className="text-2xl font-light text-zinc-500">{light}</span>}
          <h1 className="text-2xl font-bold text-zinc-900 tracking-tight">{bold}</h1>
        </div>
        {sub && <div className="text-xs text-zinc-500 font-normal mt-1 max-w-2xl">{sub}</div>}
      </div>

      {actions && <div className="flex items-center gap-3 z-10 flex-wrap">{actions}</div>}

      {decor && (
        <div className="absolute left-4 -bottom-6 opacity-15 pointer-events-none select-none w-28 h-28 hidden md:block">
          <img src={decor} alt="" className="w-full h-full object-contain" />
        </div>
      )}
    </div>
  );
}
