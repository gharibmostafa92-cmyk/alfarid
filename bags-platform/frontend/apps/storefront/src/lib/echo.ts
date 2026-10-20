import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
  interface Window {
    Pusher: typeof Pusher;
    Echo: Echo<any>;
  }
}

export function initEcho() {
  if (typeof window === 'undefined') return null;

  window.Pusher = Pusher;

  return new Echo({
    broadcaster: 'reverb',
    key: process.env.NEXT_PUBLIC_REVERB_APP_KEY || 'bags-app-key',
    wsHost: process.env.NEXT_PUBLIC_REVERB_HOST || 'localhost',
    wsPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT) || 8080,
    wssPort: Number(process.env.NEXT_PUBLIC_REVERB_PORT) || 8080,
    forceTLS: (process.env.NEXT_PUBLIC_REVERB_SCHEME || 'http') === 'https',
    enabledTransports: ['ws', 'wss'],
  });
}
