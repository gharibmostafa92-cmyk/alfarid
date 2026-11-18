export type PosSaleTrace = <T>(send: () => Promise<T>) => Promise<T>;

let tracePosSale: PosSaleTrace = (send) => send();

export function configurePosSaleTracing(trace?: PosSaleTrace): void {
  tracePosSale = trace ?? ((send) => send());
}

export function runPosSaleWithTrace<T>(send: () => Promise<T>): Promise<T> {
  return tracePosSale(send);
}
