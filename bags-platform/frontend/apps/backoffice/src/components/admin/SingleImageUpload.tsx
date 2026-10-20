import React, { useState } from 'react';
import { UploadCloud, Trash2, RefreshCw, AlertCircle } from 'lucide-react';
import { Button } from '@alfarid/ui';

export interface SingleImageUploadProps {
  value?: string | null;
  onChange?: (val: string | null) => void;
  rules?: string[];
  error?: string | null;
}

export function SingleImageUpload({
  value,
  onChange,
  rules = ['JPG · PNG · WebP', 'حتى 4MB', 'حتى 6000×6000', 'SVG غير مدعوم'],
  error,
}: SingleImageUploadProps) {
  const [status, setStatus] = useState<'idle' | 'uploading' | 'done'>(value ? 'done' : 'idle');
  const [progress, setProgress] = useState(0);

  const simulatePick = () => {
    setStatus('uploading');
    setProgress(0);
    let val = 0;
    const interval = setInterval(() => {
      val += 20;
      setProgress(Math.min(val, 100));
      if (val >= 100) {
        clearInterval(interval);
        setStatus('done');
        onChange && onChange('https://images.unsplash.com/photo-1548036328-c9fa89d128fa?w=600&auto=format&fit=crop&q=60');
      }
    }, 100);
  };

  const handleRemove = () => {
    setStatus('idle');
    onChange && onChange(null);
  };

  if (status === 'done' && value) {
    return (
      <div className="flex items-center gap-3 p-3 bg-zinc-50 border border-zinc-200 rounded-xl" dir="rtl">
        <div className="w-16 h-16 rounded-lg bg-zinc-100 border border-zinc-200 overflow-hidden shrink-0">
          <img src={value} alt="Preview" className="w-full h-full object-cover" />
        </div>
        <div className="flex-1 min-w-0 text-right">
          <div className="text-xs font-semibold text-zinc-900 truncate" dir="ltr">
            cover-image.webp
          </div>
          <div className="text-[11px] text-zinc-500 mt-0.5" dir="ltr">
            1200×1200 · 312KB
          </div>
        </div>
        <div className="flex items-center gap-2">
          <Button type="button" variant="outline" size="sm" onClick={simulatePick}>
            <RefreshCw className="w-3.5 h-3.5 ml-1" />
            استبدال
          </Button>
          <button
            type="button"
            onClick={handleRemove}
            className="p-1.5 text-red-600 hover:bg-red-50 rounded-lg transition-colors"
            title="حذف الصورة"
          >
            <Trash2 className="w-4 h-4" />
          </button>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-1.5" dir="rtl">
      <div
        onClick={status === 'idle' ? simulatePick : undefined}
        className={`border-2 border-dashed rounded-xl p-5 text-center transition-all cursor-pointer ${
          error
            ? 'border-red-400 bg-red-50/20'
            : 'border-zinc-300 hover:border-brand-500 bg-zinc-50/50 hover:bg-zinc-50'
        }`}
      >
        {status === 'uploading' ? (
          <div className="py-2 flex flex-col items-center">
            <span className="text-xs font-bold text-zinc-800 mb-2">جاري الرفع… {progress}%</span>
            <div className="w-48 h-2 bg-zinc-200 rounded-full overflow-hidden">
              <div
                className="h-full bg-brand-600 transition-all duration-150"
                style={{ width: `${progress}%` }}
              />
            </div>
          </div>
        ) : (
          <div className="flex flex-col items-center">
            <div className="w-10 h-10 rounded-full bg-zinc-100 flex items-center justify-center text-zinc-500 mb-2">
              <UploadCloud className="w-5 h-5" />
            </div>
            <div className="text-xs font-bold text-zinc-800">
              اسحب الصورة هنا أو اضغط للاختيار
            </div>
            <div className="flex flex-wrap items-center justify-center gap-x-2 gap-y-1 text-[11px] text-zinc-400 mt-2">
              {rules.map((rule, idx) => (
                <React.Fragment key={rule}>
                  <span>{rule}</span>
                  {idx < rules.length - 1 && <span>•</span>}
                </React.Fragment>
              ))}
            </div>
          </div>
        )}
      </div>
      {error && (
        <div className="flex items-center gap-1.5 text-xs text-red-600">
          <AlertCircle className="w-3.5 h-3.5 shrink-0" />
          <span>{error}</span>
        </div>
      )}
    </div>
  );
}
