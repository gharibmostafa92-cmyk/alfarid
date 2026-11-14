export interface TabCoordinatorOptions {
  channelName?: string;
  channelFactory?: () => any;
  onDuplicate?: () => void;
  onAvailable?: () => void;
}

export function createTabCoordinator(opts: TabCoordinatorOptions = {}) {
  const channelName = opts.channelName ?? 'pos-tab-channel';
  const myId = globalThis.crypto?.randomUUID?.() ?? String(Math.random());
  let channel: any = null;
  let owner: string | null = null;

  const start = () => {
    try {
      if (opts.channelFactory) {
        channel = opts.channelFactory();
      } else if (typeof window !== 'undefined' && 'BroadcastChannel' in window) {
        channel = new window.BroadcastChannel(channelName);
      }
    } catch {
      return;
    }

    if (!channel) return;

    channel.onmessage = (event: MessageEvent) => {
      const data = event.data;
      if (!data || data.from === myId) return;

      if (data.type === 'hello' && !owner) {
        channel?.postMessage({ type: 'here', from: myId, to: data.from });
      } else if (data.type === 'takeover' || (data.type === 'here' && (data.to == null || data.to === myId))) {
        owner = data.from;
        opts.onDuplicate?.();
      } else if (data.type === 'leave' && owner === data.from) {
        owner = null;
        opts.onAvailable?.();
      }
    };

    // Announce ourselves to see if an older tab exists
    channel.postMessage({ type: 'hello', from: myId });
    globalThis.addEventListener?.('pagehide', leave);
    globalThis.addEventListener?.('pageshow', announce);
  };

  const announce = () => channel?.postMessage({ type: 'hello', from: myId });
  const leave = () => {
    if (!owner) channel?.postMessage({ type: 'leave', from: myId });
  };

  const claim = () => {
    owner = null;
    try {
      channel?.postMessage({ type: 'takeover', from: myId });
    } catch {
      // ignore
    }
  };

  const destroy = () => {
    globalThis.removeEventListener?.('pagehide', leave);
    globalThis.removeEventListener?.('pageshow', announce);
    try {
      leave();
      channel?.close();
    } catch {
      // ignore
    }
    channel = null;
  };

  return {
    start,
    claim,
    destroy,
  };
}
