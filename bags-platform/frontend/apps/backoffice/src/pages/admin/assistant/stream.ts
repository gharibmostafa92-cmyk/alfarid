export interface AgentEvent { event: string; data: Record<string, unknown> }

function parseFrame(frame: string): AgentEvent[] {
  if (frame.trim() === '') return [];
  const lines = frame.split('\n').filter(l => !l.startsWith(':'));
  const event = lines.find(l => l.startsWith('event:'))?.slice(6).trim();
  const data = lines
    .filter(l => l.startsWith('data:'))
    .map(l => (l.startsWith('data: ') ? l.slice(6) : l.slice(5)))
    .join('\n');
  if (!event || !data) return [];
  try {
    const parsed: unknown = JSON.parse(data);
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? [{ event, data: parsed as Record<string, unknown> }] : [];
  } catch {
    // A non-JSON error frame (proxy 502/trimmed stream) still carries the
    // message — surface it instead of silently dropping the frame.
    return event === 'error' ? [{ event, data: { message: data } }] : [];
  }
}

/**
 * Parses a complete SSE payload. While the stream is live (`complete=false`)
 * the trailing frame may be a partial write, so it is skipped; once the HTTP
 * response has finished (`complete=true`) every frame counts. Dropping the
 * last frame of a finished body deleted the final `reply` event and faked a
 * "conversation interrupted" error for already-saved replies.
 */
export function parseAgentEvents(text: string, complete = false): AgentEvent[] {
  const frames = text.replace(/\r\n/g, '\n').split('\n\n');
  if (!complete) frames.pop(); // The final frame may still be arriving over the network.
  return frames.flatMap(parseFrame);
}

/**
 * Incremental SSE parsing for onDownloadProgress. Each progress event
 * carries the FULL responseText, so re-parsing from scratch every chunk is
 * O(n^2) and freezes the UI on long replies. The parser remembers how many
 * bytes it already consumed and only parses newly completed frames,
 * buffering the trailing partial frame for the next chunk. Call flush()
 * once the request completes to emit the buffered tail as a final frame
 * instead of re-parsing the whole body.
 */
export function createIncrementalSseParser(): { feed(responseText: string): AgentEvent[]; flush(): AgentEvent[] } {
  let cursor = 0;
  let pending = '';
  let trailingCr = false;
  return {
    feed(responseText: string): AgentEvent[] {
      if (responseText.length < cursor) { cursor = 0; pending = ''; trailingCr = false; }
      let safeLen = responseText.length;
      // Hold back trailing U+FFFD replacement chars in case an incomplete UTF-8 sequence completes on next chunk.
      while (safeLen > cursor && responseText.charCodeAt(safeLen - 1) === 0xfffd) {
        safeLen--;
      }
      let chunk = responseText.slice(cursor, safeLen);
      cursor = safeLen;
      if (trailingCr) {
        chunk = '\r' + chunk;
        trailingCr = false;
      }
      if (chunk.endsWith('\r')) {
        trailingCr = true;
        chunk = chunk.slice(0, -1);
      }
      pending += chunk.replace(/\r\n/g, '\n');
      const frames = pending.split('\n\n');
      pending = frames.pop() ?? '';
      if (frames.length === 0) return [];
      return frames.flatMap(parseFrame);
    },
    flush(): AgentEvent[] {
      const tail = pending + (trailingCr ? '\r' : '');
      pending = '';
      trailingCr = false;
      return parseFrame(tail.replace(/\r\n/g, '\n'));
    },
  };
}
