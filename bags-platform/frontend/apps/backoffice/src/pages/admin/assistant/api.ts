import { defaultApiClient as api } from '@alfarid/api-client';
import { createIncrementalSseParser } from './stream';
const BASE = '/api/v1/admin/ai';
export interface UiBlock { type: string; [k: string]: unknown }
export interface ConvMsg { id: number; role: string; content: string | null; ui_blocks: UiBlock[] | null; created_at: string }
export interface Conv { id: number; user_id: number; is_pinned: boolean; channel?: 'web' | 'whatsapp'; title: string | null; model: string; total_tokens: number; last_message_at: string | null; messages?: ConvMsg[]; pending_actions?: { id: string; status: string; expires_at: string; created_at?: string }[] }
export interface AgentSettings {
  ai_enabled: boolean;
  ai_customer_tools_enabled?: boolean;
  ai_document_analysis_enabled?: boolean;
  ai_catalog_tools_enabled?: boolean;
  ai_catalog_vision_enabled?: boolean;
  ai_catalog_copywriting_enabled?: boolean;
  ai_catalog_bulk_price_max_items?: number;
  ai_pricing_tools_enabled?: boolean;
  ai_promotions_tools_enabled?: boolean;
  ai_pricing_max_items_per_action?: number;
  ai_coupon_high_risk_percent?: number;
  ai_price_below_cost_policy?: string;
  ai_pricing_vision_enabled?: boolean;
  ai_max_steps?: number;
  ai_daily_token_budget_per_user: number;
  ai_disabled_tools: string[];
  ai_action_ttl_minutes: number;
  ai_retention_days: number;
  ai_bulk_max_items: number;
  ai_dealer_review_sla_hours?: number;
  ai_credit_limit_confirm_threshold?: number;
  ai_finance_tools_enabled?: boolean;
  ai_finance_wallet_tools_enabled?: boolean;
  ai_finance_receipt_vision_enabled?: boolean;
  ai_finance_proactive_enabled?: boolean;
  ai_finance_share_balances_with_model?: boolean;
  ai_finance_high_risk_amount?: number;
  ai_wallet_adjust_max_amount?: number;
  ai_shipping_tools_enabled?: boolean;
  ai_storefront_tools_enabled?: boolean;
  ai_storefront_place_order_enabled?: boolean;
  ai_inventory_tools_enabled?: boolean;
  ai_inventory_vision_enabled?: boolean;
  ai_inventory_share_cost_with_model?: boolean;
  ai_inventory_proactive_enabled?: boolean;
  ai_inventory_high_risk_value?: number;
  ai_whatsapp_enabled?: boolean;
  ai_whatsapp_allow_writes?: boolean;
  ai_whatsapp_max_risk?: 'low' | 'medium' | 'high';
  ai_whatsapp_disabled_tools?: string[];
  ai_whatsapp_idle_rotate_hours?: number;
  ai_whatsapp_link_token_ttl_minutes?: number;
  ai_whatsapp_pin_required?: boolean;
  ai_whatsapp_allow_notice_approval?: boolean;
  ai_whatsapp_quote_max_discount_percent?: number;
  ai_wholesale_tools_enabled?: boolean;
  ai_wholesale_receipt_vision_enabled?: boolean;
  ai_wholesale_shadow_mode?: boolean;
  ai_wholesale_quote_sla_hours?: number;
  ai_wholesale_notice_sla_hours?: number;
  ai_wholesale_reminder_weekly_cap?: number;
  ai_quote_high_risk_discount_percent?: number;
  ai_quote_high_risk_total?: number;
  ai_max_tokens_per_cycle?: number;
  ai_tool_result_max_chars?: number;
  ai_max_concurrent_sessions?: number;
}

/** Keys whose value changed between current settings and the last fetched snapshot. */
export function changedSettingKeys(settings: AgentSettings, original: AgentSettings, only?: (keyof AgentSettings)[]): (keyof AgentSettings)[] {
  const keys = (only ?? Object.keys(settings)) as (keyof AgentSettings)[];
  return keys.filter(key => JSON.stringify(settings[key]) !== JSON.stringify(original[key]));
}

/**
 * Changed-keys-only diff for saveSettings. The backend requires the `data`
 * and `expected` key sets to match exactly, so both carry only changed keys.
 * Sending the full snapshot would overwrite other tabs' saves with stale
 * values (or 409-loop); a diff touches only what this tab edited.
 */
export function diffSettings(settings: AgentSettings, original: AgentSettings, only?: (keyof AgentSettings)[]): { data: Partial<AgentSettings>; expected: Partial<AgentSettings> } {
  const data: Partial<AgentSettings> = {};
  const expected: Partial<AgentSettings> = {};
  for (const key of changedSettingKeys(settings, original, only)) {
    (data as Record<string, unknown>)[key] = settings[key];
    (expected as Record<string, unknown>)[key] = original[key];
  }
  return { data, expected };
}

/**
 * Strict allowlist for assistant document downloads. Only paths served by
 * our own admin API may be fetched with the staff auth token — never an
 * absolute third-party URL, protocol-relative URL, or a path with `..`.
 * The backend issues absolute URLs (Laravel route()), so absolute URLs are
 * accepted only when their origin matches the configured API base.
 */
const DOWNLOAD_PATH = /^\/api\/v1\/admin\/(?:customers\/[A-Za-z0-9_-]+\/documents\/[A-Za-z0-9_-]+|dealers\/[A-Za-z0-9_-]+\/documents\/[A-Za-z0-9_-]+|documents\/[A-Za-z0-9_-]+)\/download$/;

export function resolveSafeDownloadPath(url: unknown): string | undefined {
  if (typeof url !== 'string' || url === '') return undefined;
  if (/[\s\\]/.test(url) || url.includes('..')) return undefined;
  if (url.startsWith('/')) {
    if (url.startsWith('//')) return undefined;
    return DOWNLOAD_PATH.test(url) ? url : undefined;
  }
  let parsed: URL;
  try {
    parsed = new URL(url);
  } catch {
    return undefined;
  }
  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return undefined;
  if (parsed.username !== '' || parsed.password !== '') return undefined;
  const base = typeof api.defaults.baseURL === 'string' ? api.defaults.baseURL : '';
  let apiOrigin = '';
  try {
    apiOrigin = new URL(base || window.location.origin).origin;
  } catch {
    return undefined;
  }
  if (parsed.origin !== apiOrigin) return undefined;
  if (parsed.hash !== '') return undefined;
  return DOWNLOAD_PATH.test(parsed.pathname) ? parsed.pathname : undefined;
}

/** Strips path separators and control characters from server-suggested filenames. */
export function sanitizeDownloadName(name: unknown, fallback: string): string {
  const base = String(name ?? '').split(/[\\/]/).pop() ?? '';
  const clean = base.replace(/[\u0000-\u001f\u007f]/g, '').trim().replace(/^\.+/, '').slice(0, 180);
  return clean !== '' ? clean : fallback;
}

const QR_DATA_URL = /^data:image\/(png|webp|svg\+xml);base64,[A-Za-z0-9+/=]+$/;

/** QR/link codes must be strict base64 data-image URLs, never remote or script sources. */
export function isSafeQrDataUrl(src: unknown): src is string {
  return typeof src === 'string' && QR_DATA_URL.test(src);
}

const DATA_IMAGE_URL = /^data:image\/(png|jpeg|webp|gif|svg\+xml);base64,[A-Za-z0-9+/=]+$/;

/**
 * Image sources carry no auth headers, but the protocol is still checked:
 * same-origin relative paths, strict base64 data-images, or plain http(s)
 * URLs (backend serves media/thumbs as absolute CDN URLs). Anything else —
 * javascript:, data:text/html, blob:, credentials in URL — is rejected.
 */
export function isSafeImageSrc(src: unknown): src is string {
  if (typeof src !== 'string' || src === '' || /[\s\\]/.test(src)) return false;
  if (src.startsWith('/')) return !src.startsWith('//') && !src.includes('..');
  if (src.startsWith('data:')) return DATA_IMAGE_URL.test(src);
  let parsed: URL;
  try {
    parsed = new URL(src);
  } catch {
    return false;
  }
  if (parsed.protocol !== 'http:' && parsed.protocol !== 'https:') return false;
  return parsed.username === '' && parsed.password === '' && parsed.hash === '';
}
export interface ToolStatRow { tool: string; total: number; executed: number; cancelled: number; failed: number; pending: number; confirm_rate: number; cancel_rate: number; needs_attention: boolean }
export interface ToolStatsSummary { total_proposals: number; executed_count: number; cancelled_count: number; failed_409_count: number; confirm_rate: number; cancel_rate: number; conflict_409_rate: number; tools: ToolStatRow[] }
export interface UploadedAttachment { id: string; filename: string; mime: string; size: number; thumb_url: string }
export const assistantApi = {
  list: () => api.get<{ data: Conv[] }>(`${BASE}/conversations`).then(r => r.data.data ?? (r.data as unknown as Conv[])),
  listPaginated: (page = 1, q = '', all = false) => api.get<{ data: Conv[]; current_page: number; last_page: number; total: number; total_tokens: number }>(`${BASE}/conversations`, { params: { page, per_page: 20, q, ...(all ? { all: 1 } : {}) } }).then(r => r.data),
  pin: (id: number, is_pinned: boolean) => api.patch(`${BASE}/conversations/${id}/pin`, { is_pinned }),
  settings: () => api.get<{ data: AgentSettings; tools: string[] }>(`${BASE}/settings`).then(r => r.data),
  // Partials: callers send changed-keys-only diffs (see diffSettings); the
  // backend validates that data and expected share identical key sets.
  saveSettings: (data: Partial<AgentSettings>, expected: Partial<AgentSettings>) => api.put(`${BASE}/settings`, { ...data, expected }),
  usage: (all: boolean) => api.get<{ data: { day: string; input_tokens: number; output_tokens: number }[]; tool_stats?: ToolStatsSummary }>(`${BASE}/usage`, { params: { ...(all ? { all: 1 } : {}) } }).then(r => r.data.data),
  usageWithStats: (all: boolean, channel?: string) => api.get<{ data: { day: string; input_tokens: number; output_tokens: number }[]; tool_stats?: ToolStatsSummary }>(`${BASE}/usage`, { params: { ...(all ? { all: 1 } : {}), ...(channel ? { channel } : {}) } }).then(r => r.data),
  whatsappStatus: () => api.get<{ data: { state: string; phone?: string | null; last_connected_at?: string | null } }>(`${BASE}/whatsapp/status`).then(r => r.data.data),
  whatsappQr: () => api.get<{ data: { data_url?: string } }>(`${BASE}/whatsapp/qr`).then(r => r.data.data),
  whatsappLogout: () => api.post(`${BASE}/whatsapp/logout`),
  whatsappIdentities: () => api.get<{ data: { id: number; user_id: number; name: string | null; phone_e164: string; status: string; has_pin: boolean; verified_at: string | null }[] }>(`${BASE}/whatsapp/identities`).then(r => r.data.data),
  whatsappStaff: (q: string) => api.get<{ data: { id: number; name: string }[] }>(`${BASE}/whatsapp/staff`, { params: { q } }).then(r => r.data.data),
  whatsappSaveIdentity: (body: { user_id: number; phone: string; pin?: string }) => api.post(`${BASE}/whatsapp/identities`, body),
  whatsappLink: (id: number) => api.post<{ data: { url: string; expires_at: string; qr_data_url: string | null } }>(`${BASE}/whatsapp/identities/${id}/link`).then(r => r.data.data),
  whatsappRevoke: (id: number) => api.post(`${BASE}/whatsapp/identities/${id}/revoke`),
  whatsappMonitor: () => api.get<{ data: { gateway: { state: string }; counts: { status: string; ignore_reason: string | null; aggregate: number }[]; recent: { id: number; status: string; ignore_reason: string | null; created_at: string; updated_at: string }[] } }>(`${BASE}/whatsapp/monitor`).then(r => r.data.data),
  create: () => api.post<{ data: Conv }>(`${BASE}/conversations`).then(r => r.data.data),
  show: (id: number) => api.get<{ data: Conv }>(`${BASE}/conversations/${id}`).then(r => r.data.data),
  remove: (id: number) => api.delete(`${BASE}/conversations/${id}`),
  uploadAttachments: (id: number, files: File[]) => {
    const form = new FormData();
    files.forEach(file => form.append('files[]', file));
    return api.post<{ data: UploadedAttachment[] }>(`${BASE}/conversations/${id}/attachments`, form, { headers: { 'Content-Type': 'multipart/form-data' } }).then(r => r.data.data);
  },
  send: (id: number, body: { text: string; selection?: { entity: string; id: number | string; label?: string }; mentions?: { entity: string; id: number | string }[]; attachments?: string[] }) =>
    api.post<{ data: { text: string; ui_blocks: UiBlock[]; message_id: number } }>(`${BASE}/conversations/${id}/messages`, body, { timeout: 600000 }).then(r => r.data.data),
  stream: async (id: number, body: { text: string; selection?: { entity: string; id: number | string; label?: string }; mentions?: { entity: string; id: number | string }[]; attachments?: string[] }, onStatus: (message: string) => void) => {
    // Parse incoming SSE frames incrementally to avoid O(n^2) re-parsing freezes on long streams.
    const parser = createIncrementalSseParser();
    const seen: { event: string; data: Record<string, unknown> }[] = [];
    await api.post<string>(`${BASE}/conversations/${id}/stream`, body, {
      timeout: 600000, responseType: 'text', headers: { Accept: 'text/event-stream' },
      onDownloadProgress: progress => {
        const text = (progress.event?.target as XMLHttpRequest | undefined)?.responseText;
        if (typeof text !== 'string') return;
        for (const frame of parser.feed(text)) {
          seen.push(frame);
          if (frame.event === 'status' && typeof frame.data.message === 'string') onStatus(frame.data.message);
        }
      },
    });
    const events = [...seen, ...parser.flush()];
    const failure = events.find(e => e.event === 'error');
    if (failure) throw { response: { data: { message: String(failure.data.message ?? 'تعذر إتمام طلب المساعد.') } } };
    if (!events.some(e => e.event === 'reply')) throw new Error('المحادثة انقطعت قبل اكتمال الرد.');
  },
  confirm: (actionId: string, typed_confirmation?: string, selected_items?: string[]) =>
    api.post<{ data: { ok: boolean; message: string; result: Record<string, unknown>; ui_block: UiBlock; status: string } }>(`${BASE}/actions/${actionId}/confirm`, { typed_confirmation, selected_items }).then(r => r.data.data),
  cancel: (actionId: string) => api.post<{ data: { status: string } }>(`${BASE}/actions/${actionId}/cancel`).then(r => r.data.data),
  undo: (actionId: string) => api.post<{ data: { ok: boolean; message: string; status: string } }>(`${BASE}/actions/${actionId}/undo`).then(r => r.data.data),
  welcome: () => api.get<{ data: UiBlock | null }>(`${BASE}/welcome`).then(r => r.data.data),
  mentions: (q: string) => api.get<{ data: Record<string, { id: number | string; label: string; meta?: string }[]> }>(`${BASE}/mentions`, { params: { q } }).then(r => r.data.data),
};
