export interface AgentEvent { event: string; data: Record<string, unknown> }
export function parseAgentEvents(text: string): AgentEvent[] {
  const frames = text.replace(/\r\n/g, '\n').split('\n\n');
  frames.pop(); // The final frame may still be arriving over the network.
  return frames.flatMap(frame => {
    const lines = frame.split('\n');
    const event = lines.find(l => l.startsWith('event:'))?.slice(6).trim();
    const data = lines.filter(l => l.startsWith('data:')).map(l => l.slice(5).trim()).join('\n');
    if (!event || !data) return [];
    try { const parsed = JSON.parse(data); return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? [{ event, data: parsed }] : []; }
    catch { return []; }
  });
}
