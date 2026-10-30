import { defaultApiClient as api } from '@alfarid/api-client';
import { parseAgentEvents } from './stream';
const BASE = '/api/v1/admin/ai';
export interface UiBlock { type: string; [k: string]: unknown }
export interface ConvMsg { id: number; role: string; content: string | null; ui_blocks: UiBlock[] | null; created_at: string }
export interface Conv { id: number; user_id: number; is_pinned: boolean; title: string | null; model: string; total_tokens: number; last_message_at: string | null; messages?: ConvMsg[]; pending_actions?: { id: string; status: string; expires_at: string }[] }
export interface AgentSettings { ai_enabled: boolean; ai_daily_token_budget_per_user: number; ai_disabled_tools: string[]; ai_action_ttl_minutes: number; ai_retention_days: number; ai_bulk_max_items: number }
export const assistantApi = {
  list: () => api.get<{ data: Conv[] }>(`${BASE}/conversations`).then(r => r.data.data ?? (r.data as unknown as Conv[])),
  listPaginated: (page = 1, q = '', all = false) => api.get<{ data: Conv[]; last_page: number }>(`${BASE}/conversations`, { params: { page, per_page: 20, q, ...(all ? { all: 1 } : {}) } }).then(r => r.data),
  pin: (id: number, is_pinned: boolean) => api.patch(`${BASE}/conversations/${id}/pin`, { is_pinned }),
  settings: () => api.get<{ data: AgentSettings; tools: string[] }>(`${BASE}/settings`).then(r => r.data),
  saveSettings: (data: AgentSettings, expected: AgentSettings) => api.put(`${BASE}/settings`, { ...data, expected }),
  usage: (all: boolean) => api.get<{ data: { day: string; input_tokens: number; output_tokens: number }[] }>(`${BASE}/usage`, { params: { ...(all ? { all: 1 } : {}) } }).then(r => r.data.data),
  create: () => api.post<{ data: Conv }>(`${BASE}/conversations`).then(r => r.data.data),
  show: (id: number) => api.get<{ data: Conv }>(`${BASE}/conversations/${id}`).then(r => r.data.data),
  remove: (id: number) => api.delete(`${BASE}/conversations/${id}`),
  send: (id: number, body: { text: string; selection?: { entity: string; id: number | string; label?: string }; mentions?: { entity: string; id: number | string }[] }) =>
    api.post<{ data: { text: string; ui_blocks: UiBlock[]; message_id: number } }>(`${BASE}/conversations/${id}/messages`, body, { timeout: 600000 }).then(r => r.data.data),
  stream: async (id: number, body: { text: string; selection?: { entity: string; id: number | string; label?: string }; mentions?: { entity: string; id: number | string }[] }, onStatus: (message: string) => void) => {
    const response = await api.post<string>(`${BASE}/conversations/${id}/stream`, body, {
      timeout: 600000, responseType: 'text', headers: { Accept: 'text/event-stream' },
      onDownloadProgress: progress => {
        const text = (progress.event?.target as XMLHttpRequest | undefined)?.responseText;
        if (typeof text !== 'string') return;
        const statuses = parseAgentEvents(text).filter(e => e.event === 'status');
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
  mentions: (q: string) => api.get<{ data: Record<string, { id: number | string; label: string; meta?: string }[]> }>(`${BASE}/mentions`, { params: { q } }).then(r => r.data.data),
};
