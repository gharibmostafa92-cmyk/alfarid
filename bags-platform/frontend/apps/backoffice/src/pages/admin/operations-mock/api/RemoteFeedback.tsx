import { AdminLoading } from '../../../../components/admin/AdminLoading';
import { Empty, Note } from '../ui';
import { USE_MOCK } from './source';
import type { RemoteState } from './useRemote';

export function RemoteFeedback({ state }: { state: Pick<RemoteState<unknown>, 'loading' | 'error' | 'usingMock' | 'reload'> }) {
  if (state.loading) return <AdminLoading />;
  if (state.usingMock && !USE_MOCK) return <Note tone="red">تعذّر الاتصال بالخادم؛ البيانات المعروضة تجريبية ولا يمكن حفظ التغييرات.</Note>;
  if (state.error) return <Empty title={state.error.message} action={<button className="btn btn-soft" onClick={state.reload}>إعادة المحاولة</button>} />;
  return null;
}
