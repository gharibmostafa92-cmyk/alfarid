import React from 'react';
import { cn } from '../lib/utils';

export interface Column<T> {
  header: string;
  accessorKey?: keyof T;
  cell?: (row: T) => React.ReactNode;
  className?: string;
}

export interface DataTableProps<T> {
  columns: Column<T>[];
  data: T[];
  isLoading?: boolean;
  emptyMessage?: string;
  className?: string;
}

export function DataTable<T extends Record<string, any>>({
  columns,
  data,
  isLoading = false,
  emptyMessage = 'لا توجد بيانات متاحة',
  className,
}: DataTableProps<T>) {
  return (
    <div className={cn('w-full overflow-x-auto rounded-xl border border-zinc-200 bg-white', className)} dir="rtl">
      <table className="w-full text-right text-sm">
        <thead className="bg-zinc-50 border-b border-zinc-200 text-xs font-semibold text-zinc-600 uppercase">
          <tr>
            {columns.map((col, index) => (
              <th key={index} className={cn('px-4 py-3.5', col.className)}>
                {col.header}
              </th>
            ))}
          </tr>
        </thead>
        <tbody className="divide-y divide-zinc-200">
          {isLoading ? (
            <tr>
              <td colSpan={columns.length} className="py-12 text-center text-zinc-500">
                <div className="flex items-center justify-center gap-2">
                  <div className="h-4 w-4 animate-spin rounded-full border-2 border-brand-500 border-t-transparent" />
                  <span>جاري التحميل...</span>
                </div>
              </td>
            </tr>
          ) : data.length === 0 ? (
            <tr>
              <td colSpan={columns.length} className="py-12 text-center text-zinc-500">
                {emptyMessage}
              </td>
            </tr>
          ) : (
            data.map((row, rowIndex) => (
              <tr key={rowIndex} className="hover:bg-zinc-50/70 transition-colors">
                {columns.map((col, colIndex) => (
                  <td key={colIndex} className={cn('px-4 py-3 text-zinc-700', col.className)}>
                    {col.cell ? col.cell(row) : col.accessorKey ? String(row[col.accessorKey] ?? '-') : null}
                  </td>
                ))}
              </tr>
            ))
          )}
        </tbody>
      </table>
    </div>
  );
}
