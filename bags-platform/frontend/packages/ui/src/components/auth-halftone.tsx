'use client';
import React, { useRef, useCallback, useEffect } from 'react';
import { BIRKIN_IMG_DATA } from '../assets/birkinHalftone';

export function AuthHalftone() {
  const gridStep = 7;
  // Canvas refs
  const canvasRef = useRef<HTMLCanvasElement | null>(null);
  const birkinPixelsRef = useRef<Uint8ClampedArray | null>(null);
  const renderCanvasRef = useRef<() => void>(() => {});
  const mousePosRef = useRef({ x: -1000, y: -1000, isHovering: false });

  const W = 560;
  const H = 640;

  // Canvas drawing routine
  const renderCanvas = useCallback(() => {
    const canvas = canvasRef.current;
    if (!canvas) return;
    const ctx = canvas.getContext('2d');
    if (!ctx) return;

    ctx.fillStyle = '#ffffff';
    ctx.fillRect(0, 0, W, H);

    const activePixels = birkinPixelsRef.current;
    if (!activePixels) return;

    const step = gridStep;
    const half = step / 2;
    const { x: mx, y: my, isHovering: isHover } = mousePosRef.current;

    ctx.fillStyle = '#09090b';

    for (let y = Math.floor(half); y < H; y += step) {
      for (let x = Math.floor(half); x < W; x += step) {
        const idx = (y * W + x) * 4;
        const luma = activePixels[idx] / 255.0;
        const dark = 1.0 - luma;
        if (dark < 0.06) continue;

        let drawX = x;
        let drawY = y;
        let scaleBoost = 1.0;

        if (isHover) {
          const dx = x - mx;
          const dy = y - my;
          const dist = Math.sqrt(dx * dx + dy * dy);
          if (dist < 110) {
            const factor = 1.0 - dist / 110.0;
            drawX += (dx / (dist + 1)) * factor * 2.2;
            drawY += (dy / (dist + 1)) * factor * 2.2;
            scaleBoost = 1.0 + factor * 0.16;
          }
        }

        const r = Math.pow(dark, 0.92) * (step * 0.68) * scaleBoost;
        if (r < 0.45) continue;

        // Diamond dot
        ctx.beginPath();
        ctx.moveTo(drawX, drawY - r);
        ctx.lineTo(drawX + r, drawY);
        ctx.lineTo(drawX, drawY + r);
        ctx.lineTo(drawX - r, drawY);
        ctx.closePath();
        ctx.fill();

        // White micro center dot
        if (dark > 0.76 && r > 2.4) {
          const ir = (dark - 0.74) * (step * 0.3);
          if (ir >= 0.7) {
            ctx.save();
            ctx.fillStyle = '#ffffff';
            ctx.beginPath();
            ctx.arc(drawX, drawY, ir, 0, Math.PI * 2);
            ctx.fill();
            ctx.restore();
          }
        }
      }
    }
  }, []);

  useEffect(() => {
    renderCanvasRef.current = renderCanvas;
    renderCanvas();
  }, [renderCanvas]);

  // Extract pixel buffers on mount
  useEffect(() => {
    const offCanvas = document.createElement('canvas');
    offCanvas.width = W;
    offCanvas.height = H;
    const offCtx = offCanvas.getContext('2d', { willReadFrequently: true });
    if (!offCtx) return;

    const extract = (img: HTMLImageElement) => {
      offCtx.fillStyle = '#ffffff';
      offCtx.fillRect(0, 0, W, H);
      offCtx.drawImage(img, 0, 0, W, H);
      return offCtx.getImageData(0, 0, W, H).data;
    };

    const bImg = new Image();
    bImg.onload = () => {
      birkinPixelsRef.current = extract(bImg);
      renderCanvasRef.current();
    };
    bImg.src = BIRKIN_IMG_DATA;

    return () => {
      bImg.onload = null;
    };
  }, []);

  // Pointer tracking
  const handlePointerMove = (clientX: number, clientY: number) => {
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches || !window.matchMedia('(hover: hover) and (pointer: fine)').matches) return;
    const canvas = canvasRef.current;
    if (!canvas) return;
    const rect = canvas.getBoundingClientRect();
    mousePosRef.current = {
      x: ((clientX - rect.left) / rect.width) * W,
      y: ((clientY - rect.top) / rect.height) * H,
      isHovering: true,
    };
    requestAnimationFrame(renderCanvas);
  };

  const handlePointerLeave = () => {
    mousePosRef.current.isHovering = false;
    requestAnimationFrame(renderCanvas);
  };

  return (
    <canvas
      ref={canvasRef}
      width={W}
      height={H}
      aria-hidden="true"
      onMouseMove={(e) => handlePointerMove(e.clientX, e.clientY)}
      onMouseLeave={handlePointerLeave}
      onTouchMove={(e) => {
        if (e.touches[0]) handlePointerMove(e.touches[0].clientX, e.touches[0].clientY);
      }}
    />
  );
}
