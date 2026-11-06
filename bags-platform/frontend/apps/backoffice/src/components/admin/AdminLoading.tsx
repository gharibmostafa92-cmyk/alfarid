import styles from './AdminLoading.css?inline';

export function AdminLoading({ label = 'جارٍ تحميل البيانات', compact = false }: { label?: string; compact?: boolean }) {
  return (
    <div className={compact ? 'admin-loading admin-loading--compact' : 'admin-loading'} role="status" aria-label={label} dir="rtl">
      <style>{styles}</style>
      <span className="admin-loading-spinner" aria-hidden="true" />
      {!compact && <div className="admin-loading-lines" aria-hidden="true"><span /><span /><span /></div>}
    </div>
  );
}
