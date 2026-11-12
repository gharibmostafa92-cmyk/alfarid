/** Audible feedback without audio files: one short high tone = added, a low double tone = not found / out of stock. */
let ctx: AudioContext | null = null;

function audio(): AudioContext | null {
  const Ctor: typeof AudioContext | undefined =
    typeof window !== 'undefined' ? (window.AudioContext ?? (window as any).webkitAudioContext) : undefined;
  if (!Ctor) return null;
  ctx ??= new Ctor();
  if (ctx.state === 'suspended') void ctx.resume();
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
    osc.start(start);
    osc.stop(start + seconds);
  } catch {
    /* audio is best-effort */
  }
}

export const beepSuccess = (): void => tone(1200, 0.07, 'sine');

export const beepError = (): void => {
  tone(300, 0.12, 'square');
  tone(220, 0.18, 'square', 0.14);
};
