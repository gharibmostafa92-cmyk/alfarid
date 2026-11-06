'use client';

import { useEffect, useState } from 'react';

export function AnimatedNumber({ value, start = 0, duration = 800, trigger, format }: {
  value: number;
  start?: number;
  duration?: number;
  trigger?: number;
  format?: (value: number) => string;
}) {
  const [display, setDisplay] = useState(start);

  useEffect(() => {
    if (!Number.isFinite(value) || start === value || duration <= 0 ||
      window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) {
      setDisplay(value);
      return;
    }
    let frame: number;
    const started = performance.now();
    setDisplay(start);
    function tick(now: number) {
      const progress = Math.min(1, (now - started) / duration);
      setDisplay(progress === 1 ? value : start + (value - start) * (1 - (1 - progress) ** 3));
      if (progress < 1) frame = requestAnimationFrame(tick);
    }
    frame = requestAnimationFrame(tick);
    return () => cancelAnimationFrame(frame);
  }, [value, start, duration, trigger]);

  const shown = Number.isInteger(value) ? Math.round(display) : display;
  return <>{format ? format(shown) : String(shown === value ? value : Number(shown.toFixed(3)))}</>;
}
