import React from 'react';
import { FolderOpen, AlertTriangle, Lock, RotateCcw, X, Info, CheckCircle2 } from 'lucide-react';
import { Button } from '@alfarid/ui';

export function EmptyState({
  title,
  text,
  action,
  icon,
}: {
  title: string;
  text?: string;
  action?: React.ReactNode;
  icon?: React.ReactNode;
}) {
  return (
    <div className="py-14 px-4 text-center flex flex-col items-center justify-center max-w-sm mx-auto">
      <div className="w-14 h-14 rounded-2xl bg-zinc-100 text-zinc-400 flex items-center justify-center mb-3">
        {icon || <FolderOpen className="w-7 h-7" />}
      </div>
      <h3 className="text-sm font-bold text-zinc-900">{title}</h3>
      {text && <p className="text-xs text-zinc-500 mt-1 leading-relaxed">{text}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  );
}

export function ErrorState({
  onRetry,
  text = 'تعذّر تحميل البيانات من الخادم. تحقق من اتصالك وحاول مرة أخرى.',
}: {
  onRetry?: () => void;
  text?: string;
}) {
  return (
    <div className="py-14 px-4 text-center flex flex-col items-center justify-center max-w-sm mx-auto">
      <div className="w-14 h-14 rounded-2xl bg-red-50 text-red-500 flex items-center justify-center mb-3">
        <AlertTriangle className="w-7 h-7" />
      </div>
      <h3 className="text-sm font-bold text-zinc-900">حدث خطأ أثناء التحميل</h3>
      <p className="text-xs text-zinc-500 mt-1 leading-relaxed">{text}</p>
      {onRetry && (
        <Button variant="outline" size="sm" className="mt-4 gap-2" onClick={onRetry}>
          <RotateCcw className="w-3.5 h-3.5" />
          <span>إعادة المحاولة</span>
        </Button>
      )}
    </div>
  );
}

export function NoAccessState({ perm, onGoBack }: { perm?: string; onGoBack?: () => void }) {
  return (
    <div className="py-14 px-4 text-center flex flex-col items-center justify-center max-w-sm mx-auto">
      <div className="w-14 h-14 rounded-2xl bg-amber-50 text-amber-600 flex items-center justify-center mb-3">
        <Lock className="w-7 h-7" />
      </div>
      <h3 className="text-sm font-bold text-zinc-900">ليس لديك صلاحية لعرض هذه الصفحة</h3>
      <p className="text-xs text-zinc-500 mt-1 leading-relaxed">
        هذه الصفحة تتطلب صلاحية خاصة. تواصل مع مدير النظام لإضافتها إلى دورك.
      </p>
      {perm && (
        <span className="mt-2 text-[11px] font-mono bg-zinc-100 text-zinc-600 px-2.5 py-0.5 rounded-md">
          {perm}
        </span>
      )}
      {onGoBack && (
        <Button variant="outline" size="sm" className="mt-4" onClick={onGoBack}>
          العودة للمنتجات
        </Button>
      )}
    </div>
  );
}

export function AlertBanner({
  tone = 'info',
  title,
  children,
  text,
  action,
  onClose,
}: {
  tone?: 'info' | 'warn' | 'danger' | 'ok';
  title?: string;
  children?: React.ReactNode;
  text?: string;
  action?: React.ReactNode;
  onClose?: () => void;
}) {
  const styles = {
    info: 'bg-blue-50/70 border-blue-200 text-blue-900',
    warn: 'bg-amber-50/70 border-amber-200 text-amber-900',
    danger: 'bg-red-50/70 border-red-200 text-red-900',
    ok: 'bg-emerald-50/70 border-emerald-200 text-emerald-900',
  };

  const icons = {
    info: <Info className="w-4 h-4 text-blue-600 shrink-0 mt-0.5" />,
    warn: <AlertTriangle className="w-4 h-4 text-amber-600 shrink-0 mt-0.5" />,
    danger: <AlertTriangle className="w-4 h-4 text-red-600 shrink-0 mt-0.5" />,
    ok: <CheckCircle2 className="w-4 h-4 text-emerald-600 shrink-0 mt-0.5" />,
  };

  return (
    <div className={`p-4 rounded-xl border flex items-start gap-3 text-xs ${styles[tone]}`} dir="rtl">
      {icons[tone]}
      <div className="flex-1">
        {title && <div className="font-bold mb-0.5">{title}</div>}
        <div className="leading-relaxed">{children || text}</div>
      </div>
      {action && <div className="shrink-0">{action}</div>}
      {onClose && (
        <button onClick={onClose} className="opacity-60 hover:opacity-100 transition-opacity">
          <X className="w-4 h-4" />
        </button>
      )}
    </div>
  );
}
