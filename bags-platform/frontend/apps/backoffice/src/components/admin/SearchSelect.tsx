import React, { useState, useRef, useEffect } from 'react';
import { ChevronDown, Search, X, Check } from 'lucide-react';

export interface SelectOption {
  value: any;
  label: string;
  sub?: string;
  disabled?: boolean;
  why?: string | null;
}

export interface SearchSelectProps {
  options: SelectOption[];
  value: any;
  onChange: (value: any) => void;
  placeholder?: string;
  searchPlaceholder?: string;
  multi?: boolean;
  clearable?: boolean;
  disabled?: boolean;
  error?: string | boolean;
}

export function SearchSelect({
  options = [],
  value,
  onChange,
  placeholder = 'اختر...',
  searchPlaceholder = 'ابحث...',
  multi = false,
  clearable = false,
  disabled = false,
  error,
}: SearchSelectProps) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState('');
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const handleClickOutside = (e: MouseEvent) => {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  const filteredOptions = options.filter(
    (o) =>
      !query ||
      `${o.label} ${o.sub || ''}`.toLowerCase().includes(query.toLowerCase())
  );

  const selectedOptions = multi
    ? options.filter((o) => (value || []).includes(o.value))
    : options.find((o) => o.value === value);

  const handleSelect = (option: SelectOption) => {
    if (option.disabled) return;
    if (multi) {
      const current = value || [];
      const updated = current.includes(option.value)
        ? current.filter((v: any) => v !== option.value)
        : [...current, option.value];
      onChange(updated);
    } else {
      onChange(option.value);
      setOpen(false);
    }
  };

  return (
    <div className="relative w-full text-right" ref={containerRef} dir="rtl">
      <button
        type="button"
        disabled={disabled}
        onClick={() => setOpen((prev) => !prev)}
        className={`w-full flex items-center justify-between min-h-[38px] px-3.5 py-1.5 rounded-lg border bg-white text-sm transition-all text-right ${
          error
            ? 'border-red-500 focus:ring-red-200'
            : open
            ? 'border-brand-500 ring-2 ring-brand-500/10'
            : 'border-zinc-300 hover:border-zinc-400'
        } ${disabled ? 'bg-zinc-50 opacity-60 cursor-not-allowed' : 'cursor-pointer'}`}
      >
        <div className="flex-1 overflow-hidden truncate">
          {multi ? (
            Array.isArray(selectedOptions) && selectedOptions.length > 0 ? (
              <span className="text-zinc-900 font-medium">
                {selectedOptions.map((o) => o.label).join('، ')}
              </span>
            ) : (
              <span className="text-zinc-400">{placeholder}</span>
            )
          ) : selectedOptions && !Array.isArray(selectedOptions) ? (
            <span className="text-zinc-900 font-medium">{selectedOptions.label}</span>
          ) : (
            <span className="text-zinc-400">{placeholder}</span>
          )}
        </div>

        <div className="flex items-center gap-1.5 shrink-0 mr-2">
          {clearable && !multi && value != null && (
            <X
              className="w-3.5 h-3.5 text-zinc-400 hover:text-zinc-600 transition-colors"
              onClick={(e) => {
                e.stopPropagation();
                onChange(null);
              }}
            />
          )}
          <ChevronDown className={`w-4 h-4 text-zinc-400 transition-transform ${open ? 'rotate-180' : ''}`} />
        </div>
      </button>

      {open && (
        <div className="absolute top-full left-0 right-0 mt-1.5 bg-white border border-zinc-200 rounded-xl shadow-xl z-50 overflow-hidden text-sm animate-in fade-in-50 duration-150">
          <div className="p-2 border-b border-zinc-100 flex items-center gap-2 bg-zinc-50/50">
            <Search className="w-4 h-4 text-zinc-400 shrink-0" />
            <input
              autoFocus
              type="text"
              value={query}
              onChange={(e) => setQuery(e.target.value)}
              placeholder={searchPlaceholder}
              className="w-full bg-transparent border-none text-xs focus:outline-none placeholder:text-zinc-400"
            />
          </div>

          <div className="max-h-60 overflow-y-auto p-1 divide-y divide-zinc-50">
            {filteredOptions.length === 0 ? (
              <div className="py-6 text-center text-xs text-zinc-400">
                لا توجد نتائج مطابقة {query ? `عن «${query}»` : ''}
              </div>
            ) : (
              filteredOptions.map((option) => {
                const isSelected = multi
                  ? (value || []).includes(option.value)
                  : value === option.value;

                return (
                  <div
                    key={String(option.value)}
                    onClick={() => handleSelect(option)}
                    className={`flex items-center justify-between px-3 py-2 rounded-lg cursor-pointer transition-colors ${
                      isSelected
                        ? 'bg-brand-50 text-brand-700 font-medium'
                        : 'hover:bg-zinc-50 text-zinc-800'
                    } ${option.disabled ? 'opacity-40 cursor-not-allowed hover:bg-transparent' : ''}`}
                  >
                    <div className="flex flex-col">
                      <span>{option.label}</span>
                      {option.sub && <span className="text-[11px] text-zinc-400">{option.sub}</span>}
                    </div>

                    <div className="flex items-center gap-2">
                      {option.why && (
                        <span className="text-[10px] text-amber-700 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded">
                          {option.why}
                        </span>
                      )}
                      {isSelected && <Check className="w-4 h-4 text-brand-600 shrink-0" />}
                    </div>
                  </div>
                );
              })
            )}
          </div>
        </div>
      )}
    </div>
  );
}
