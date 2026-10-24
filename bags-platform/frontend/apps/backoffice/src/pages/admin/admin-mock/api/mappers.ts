import type { AdminRole, AdminUser, AdminSettings } from '../data';
import type { ApiRole, ApiUser, ApiSettingsGrouped } from './types';

export const SUPER_ADMIN = 'Super Admin';

export const toAdminUser = (u: ApiUser, meId?: number): AdminUser => ({
  id: u.id,
  version: u.version,
  name: u.name,
  email: u.email,
  phone: u.phone,
  branch_id: u.branch_id,
  branch_name: u.branch?.name ?? null,      // حقل جديد
  roles: (u.roles ?? []).map(r => r.id),
  role_names: (u.roles ?? []).map(r => r.name), // حقل جديد
  is_active: u.is_active,
  created_at: u.created_at,
  me: meId === u.id,
});

export const toAdminRole = (r: ApiRole): AdminRole => ({
  id: r.id,
  version: r.version,
  builtin: r.is_builtin ?? ['Super Admin', 'cashier'].includes(r.name),
  name: r.name,
  system: r.is_system ?? r.name === SUPER_ADMIN,
  perms: r.permissions ?? [],
  users_count: r.users_count ?? 0,          // حقل جديد
});

export const roleIdsToNames = (ids: number[], roles: AdminRole[]) =>
  ids.map(id => roles.find(r => r.id === id)?.name).filter((n): n is string => !!n);
export const DOC_KEYS = ['invoice','order','purchase_order','quotation','movement','transfer',
  'stocktake','return','purchase_request','delivery','supplier_invoice','supplier_payment'] as const;

const flatten = (g: ApiSettingsGrouped) =>
  Object.values(g).reduce<Record<string, string | number | boolean | null>>((acc, grp) => ({ ...acc, ...grp }), {});

const asBool = (v: unknown) => v === true || v === 1 || v === '1';

export function toAdminSettings(g: ApiSettingsGrouped): AdminSettings {
  const f = flatten(g);
  return {
    company: {
      company_logo: String(f.company_logo ?? ''),
      company_name: String(f.company_name ?? ''),
      company_email: String(f.company_email ?? ''),
      company_phone: String(f.company_phone ?? ''),
      company_address: String(f.company_address ?? ''),
      commercial_register: String(f.commercial_register ?? ''),
    },
    tax: {
      tax_enabled: asBool(f.tax_enabled),
      tax_number: String(f.tax_number ?? ''),
      default_tax_rate: Number(f.default_tax_rate ?? 0),
      prices_include_tax: asBool(f.prices_include_tax),
    },
    numbering: Object.fromEntries(DOC_KEYS.map(k => [k, {
      prefix: String(f[`${k}_prefix`] ?? ''),
      padding: Number(f[`${k}_padding`] ?? 6),
      next_number: Number(f[`${k}_next_number`] ?? 1),
    }])),
    orders: { return_window_days: Number(f.return_window_days ?? 14) },
    purchases: { allow_self_approve_purchases: asBool(f.allow_self_approve_purchases) },
  };
}

/** بيرجّع flat map فيه المفاتيح اللي اتغيرت بس. ده اللي بيتبعت في PUT */
export function settingsPatch(current: AdminSettings, server: AdminSettings): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  (['company', 'tax', 'orders', 'purchases'] as const).forEach(g => {
    Object.entries(current[g]).forEach(([k, v]) => {
      if (JSON.stringify(v) !== JSON.stringify((server[g] as any)[k])) {
        out[k] = typeof v === 'string' && v.trim() === '' && k !== 'company_name' ? null : v;
      }
    });
  });
  DOC_KEYS.forEach(k => {
    const a = current.numbering[k], b = server.numbering[k];
    if (!a || !b) return;
    if (a.prefix !== b.prefix) out[`${k}_prefix`] = a.prefix ?? '';
    if (a.padding !== b.padding) out[`${k}_padding`] = Number(a.padding);
    if (a.next_number !== b.next_number) out[`${k}_next_number`] = Number(a.next_number);
  });
  return out;
}
export function settingsExpected(patch: Record<string, unknown>, server: AdminSettings): Record<string, unknown> {
 const flat: Record<string, unknown> = {...server.company, ...server.tax, ...server.orders, ...server.purchases};
 for (const key of DOC_KEYS) {
   const value = server.numbering[key];
   if (value) { flat[key+'_prefix'] = value.prefix; flat[key+'_padding'] = value.padding; flat[key+'_next_number'] = value.next_number; }
 }
 return Object.fromEntries(Object.keys(patch).map(key => [key, flat[key] ?? null]));
}
export function buildRolePatch(draft: {name: string; perms: string[]}, original?: {name: string; perms: string[]; version?: number}) {
 const patch: import('./types').RoleBody = {};
 if (original?.version != null) patch.expected_version = original.version;
 if (draft.name.trim() !== original?.name) patch.name = draft.name.trim();
 if (!original || draft.perms.length !== original.perms.length || draft.perms.some(p => !original.perms.includes(p))) patch.permissions = draft.perms;
 return patch;
}
