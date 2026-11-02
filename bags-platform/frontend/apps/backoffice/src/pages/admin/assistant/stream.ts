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

/**
 * L6: incremental SSE parsing for onDownloadProgress. Each progress event
 * carries the FULL responseText, so re-parsing from scratch every chunk is
 * O(n^2) and freezes the UI on long replies. The parser remembers how many
 * bytes it already consumed and only parses newly completed frames,
 * buffering the trailing partial frame for the next chunk.
 */
export function createIncrementalSseParser(): { feed(responseText: string): AgentEvent[] } {
  let cursor = 0;
  let pending = '';
  return {
    feed(responseText: string): AgentEvent[] {
      if (responseText.length < cursor) { cursor = 0; pending = ''; }
      pending += responseText.slice(cursor).replace(/\r\n/g, '\n');
      cursor = responseText.length;
      const frames = pending.split('\n\n');
      pending = frames.pop() ?? '';
      if (frames.length === 0) return [];
      return parseAgentEvents(frames.join('\n\n') + '\n\n');
    },
  };
}
