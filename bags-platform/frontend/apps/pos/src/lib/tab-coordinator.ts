export interface TabCoordinatorOptions {
  channelName?: string;
  channelFactory?: () => any;
  onDuplicate?: () => void;
}

export function createTabCoordinator(opts: TabCoordinatorOptions = {}) {
  const channelName = opts.channelName ?? 'pos-tab-channel';
  const myId = globalThis.crypto?.randomUUID?.() ?? String(Math.random());
  let channel: any = null;

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

      if (data.type === 'hello') {
        // We were here first, tell the new tab we are active
        channel?.postMessage({ type: 'here', from: myId });
      } else if (data.type === 'here' || data.type === 'takeover') {
        // An existing tab is already running or another tab took over!
        opts.onDuplicate?.();
      }
    };

    // Announce ourselves to see if an older tab exists
    channel.postMessage({ type: 'hello', from: myId });
  };

  const claim = () => {
    try {
      channel?.postMessage({ type: 'takeover', from: myId });
    } catch {
      // ignore
    }
  };

  const destroy = () => {
    try {
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
