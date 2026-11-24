/** Audible feedback without audio files: one short high tone = added, a low double tone = not found / out of stock. */
let ctx: AudioContext | null = null;

function audio(): AudioContext | null {
  const Ctor: typeof AudioContext | undefined =
    typeof window !== 'undefined' ? (window.AudioContext ?? (window as any).webkitAudioContext) : undefined;
  if (!Ctor) return null;
  if (!ctx || ctx.state === 'closed') {
    const context = new Ctor();
    ctx = context;
    window.addEventListener('pagehide', () => {
      if (ctx === context) ctx = null;
      void context.close().catch(() => undefined);
    }, { once: true });
  }
  if (ctx.state === 'suspended') void ctx.resume().catch(() => undefined);
  return ctx;
}

function tone(freq: number, seconds: number, type: OscillatorType, delay = 0): void {
  try {
    const c = audio();
    if (!c) return;
    const osc = c.createOscillator();
    const gain = c.createGain();
    const start = c.currentTime + delay;
    osc.type = type;
    osc.frequency.value = freq;
    gain.gain.setValueAtTime(0.15, start);
    gain.gain.exponentialRampToValueAtTime(0.0001, start + seconds);
    osc.connect(gain).connect(c.destination);
    osc.onended = () => {
      try {
        osc.disconnect();
        gain.disconnect();
      } catch {
        /* best-effort cleanup */
      }
    };
    osc.start(start);
    osc.stop(start + seconds);
  } catch {
    /* audio is best-effort */
  }
}

export const beepSuccess = (): void => tone(1200, 0.07, 'sine');

export async function enableOrderSound(): Promise<boolean> {
  try {
    const context = audio();
    if (!context) return false;
    if (context.state !== 'running') await context.resume();
    return context.state === 'running';
  } catch {
    return false;
  }
}

export function beepIncomingOrder(): void {
  if (ctx?.state !== 'running') return;
  tone(660, 0.16, 'sine');
  tone(880, 0.24, 'sine', 0.19);
}

export const beepError = (): void => {
  tone(300, 0.12, 'square');
  tone(220, 0.18, 'square', 0.14);
};
