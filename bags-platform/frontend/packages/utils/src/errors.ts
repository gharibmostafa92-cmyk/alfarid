/**
 * Validation error parsing utilities for indexed nested form arrays (e.g. Laravel 422 responses).
 */

/**
 * Splits an indexed validation error key (e.g. 'items.3.quantity' or 'variants.0.price')
 * into its row index and attribute field name.
 */
export function splitIndexedErrorKey(
  serverKey: string,
  prefix: string = 'items'
): { index: number; field: string } | null {
  if (!serverKey || typeof serverKey !== 'string') return null;
  const escapedPrefix = prefix.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const m = new RegExp(`^${escapedPrefix}\\.(\\d+)\\.(.+)$`).exec(serverKey);
  if (!m) return null;
  return { index: Number(m[1]), field: m[2] };
}

/**
 * Groups a flat validation errors map (e.g. from normalizeError().fields or Laravel 422 body)
 * into a per-row mapping: { [index: number]: { [field: string]: string } }.
 */
export function groupIndexedErrors(
  fields: Record<string, string | string[] | unknown> | null | undefined,
  prefix: string = 'items'
): Record<number, Record<string, string>> {
  const rows: Record<number, Record<string, string>> = {};
  if (!fields || typeof fields !== 'object') return rows;

  Object.entries(fields).forEach(([key, messages]) => {
    const hit = splitIndexedErrorKey(key, prefix);
    if (!hit) return;
    const msg = Array.isArray(messages) ? String(messages[0] ?? '') : String(messages ?? '');
    rows[hit.index] = { ...(rows[hit.index] || {}), [hit.field]: msg };
  });

  return rows;
}
