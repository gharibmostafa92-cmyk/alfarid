import { defaultApiClient as api } from '@alfarid/api-client';
import { createIncrementalSseParser, parseAgentEvents } from './stream';
const BASE = '/api/v1/admin/ai';
export interface UiBlock { type: string; [k: string]: unknown }
export interface ConvMsg { id: number; role: string; content: string | null; ui_blocks: UiBlock[] | null; created_at: string }
export interface Conv { id: number; user_id: number; is_pinned: boolean; title: string | null; model: string; total_tokens: number; last_message_at: string | null; messages?: ConvMsg[]; pending_actions?: { id: string; status: string; expires_at: string }[] }
export interface AgentSettings { ai_enabled: boolean; ai_customer_tools_enabled?: boolean; ai_document_analysis_enabled?: boolean; ai_catalog_tools_enabled?: boolean; ai_catalog_vision_enabled?: boolean; ai_catalog_copywriting_enabled?: boolean; ai_catalog_bulk_price_max_items?: number; ai_pricing_tools_enabled?: boolean; ai_promotions_tools_enabled?: boolean; ai_pricing_max_items_per_action?: number; ai_coupon_high_risk_percent?: number; ai_price_below_cost_policy?: string; ai_pricing_vision_enabled?: boolean; ai_max_steps?: number; ai_daily_token_budget_per_user: number; ai_disabled_tools: string[]; ai_action_ttl_minutes: number; ai_retention_days: number; ai_bulk_max_items: number; ai_dealer_review_sla_hours?: number; ai_credit_limit_confirm_threshold?: number; ai_finance_tools_enabled?: boolean; ai_finance_wallet_tools_enabled?: boolean; ai_finance_receipt_vision_enabled?: boolean; ai_finance_proactive_enabled?: boolean; ai_finance_share_balances_with_model?: boolean; ai_finance_high_risk_amount?: number; ai_wallet_adjust_max_amount?: number }
export interface AgentSettings {
  ai_inventory_tools_enabled?: boolean;
  ai_inventory_vision_enabled?: boolean;
  ai_inventory_share_cost_with_model?: boolean;
  ai_inventory_proactive_enabled?: boolean;
  ai_inventory_high_risk_value?: number;
}
export interface ToolStatRow { tool: string; total: number; executed: number; cancelled: number; failed: number; pending: number; confirm_rate: number; cancel_rate: number; needs_attention: boolean }
export interface ToolStatsSummary { total_proposals: number; executed_count: number; cancelled_count: number; failed_409_count: number; confirm_rate: number; cancel_rate: number; conflict_409_rate: number; tools: ToolStatRow[] }
export interface UploadedAttachment { id: string; filename: string; mime: string; size: number; thumb_url: string }
export const assistantApi = {
  list: () => api.get<{ data: Conv[] }>(`${BASE}/conversations`).then(r => r.data.data ?? (r.data as unknown as Conv[])),
  listPaginated: (page = 1, q = '', all = false) => api.get<{ data: Conv[]; last_page: number }>(`${BASE}/conversations`, { params: { page, per_page: 20, q, ...(all ? { all: 1 } : {}) } }).then(r => r.data),
  pin: (id: number, is_pinned: boolean) => api.patch(`${BASE}/conversations/${id}/pin`, { is_pinned }),
  settings: () => api.get<{ data: AgentSettings; tools: string[] }>(`${BASE}/settings`).then(r => r.data),
  saveSettings: (data: AgentSettings, expected: AgentSettings) => api.put(`${BASE}/settings`, { ...data, expected }),
  usage: (all: boolean) => api.get<{ data: { day: string; input_tokens: number; output_tokens: number }[]; tool_stats?: ToolStatsSummary }>(`${BASE}/usage`, { params: { ...(all ? { all: 1 } : {}) } }).then(r => r.data.data),
  usageWithStats: (all: boolean) => api.get<{ data: { day: string; input_tokens: number; output_tokens: number }[]; tool_stats?: ToolStatsSummary }>(`${BASE}/usage`, { params: { ...(all ? { all: 1 } : {}) } }).then(r => r.data),
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
    // L6: تحليل الإطارات الجديدة فقط — إعادة تحليل كامل responseText في
    // كل chunk كانت O(n²) وتجمّد الواجهة مع الردود الطويلة.
    const parser = createIncrementalSseParser();
    const response = await api.post<string>(`${BASE}/conversations/${id}/stream`, body, {
      timeout: 600000, responseType: 'text', headers: { Accept: 'text/event-stream' },
      onDownloadProgress: progress => {
        const text = (progress.event?.target as XMLHttpRequest | undefined)?.responseText;
        if (typeof text !== 'string') return;
        const statuses = parser.feed(text).filter(e => e.event === 'status');
        const latest = statuses.at(-1)?.data.message;
        if (typeof latest === 'string') onStatus(latest);
      },
    });
    const events = parseAgentEvents(response.data);
    const failure = events.find(e => e.event === 'error');
    if (failure) throw { response: { data: { message: String(failure.data.message) } } };
    if (!events.some(e => e.event === 'reply')) throw new Error('المحادثة انقطعت قبل اكتمال الرد.');
  },
  confirm: (actionId: string, typed_confirmation?: string, selected_items?: string[]) =>
    api.post<{ data: { ok: boolean; message: string; result: Record<string, unknown>; ui_block: UiBlock; status: string } }>(`${BASE}/actions/${actionId}/confirm`, { typed_confirmation, selected_items }).then(r => r.data.data),
  cancel: (actionId: string) => api.post<{ data: { status: string } }>(`${BASE}/actions/${actionId}/cancel`).then(r => r.data.data),
  undo: (actionId: string) => api.post<{ data: { ok: boolean; message: string; status: string } }>(`${BASE}/actions/${actionId}/undo`).then(r => r.data.data),
  welcome: () => api.get<{ data: UiBlock | null }>(`${BASE}/welcome`).then(r => r.data.data),
  mentions: (q: string) => api.get<{ data: Record<string, { id: number | string; label: string; meta?: string }[]> }>(`${BASE}/mentions`, { params: { q } }).then(r => r.data.data),
};
