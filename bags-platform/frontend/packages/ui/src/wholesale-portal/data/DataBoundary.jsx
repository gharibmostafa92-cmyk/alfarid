import { DataStateCtx } from '../ui-primitives';

export const DataBoundary = ({ api, children }) => (
  <DataStateCtx.Provider value={{ state: api.state, retry: api.retry }}>{children}</DataStateCtx.Provider>
);
