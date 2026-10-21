import { useAuthStore } from '../../stores/auth.store';
import { useCan } from '../../hooks/useCan';

/** نقطة البيع: متاحة لدور الكاشير أو صلاحية pos.access فقط. */
export function PosPage() {
  const user = useAuthStore((s) => s.user);
  const canPos = useCan('pos.access');
  const isCashier = (user?.roles ?? []).includes('cashier');

  if (!isCashier && !canPos) {
    return (
      <div style={{ padding: 40, textAlign: 'center' }}>
        <h1>غير مصرح</h1>
        <p>صفحة نقطة البيع متاحة للكاشير فقط.</p>
      </div>
    );
  }

  return (
    <div style={{ padding: 40 }}>
      <h1>نقطة البيع</h1>
      <p>واجهة الكاشير ستتوفر هنا.</p>
    </div>
  );
}

export default PosPage;
