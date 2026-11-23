'use client';

import { useEffect, useRef, useState } from 'react';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { StoreImage } from '../StoreImage';

export interface StageImage {
  url: string | null;
  alt: string;
}

export interface StageSlide extends StageImage {
  key: string;
}

interface ShowcaseStageProps {
  previous?: StageImage;
  current?: StageImage;
  next?: StageImage;
  slides?: StageSlide[];
  activeIndex?: number;
  canNavigate: boolean;
  onNavigate: (direction: number) => void;
  onSelectIndex?: (index: number) => void;
  onExplore?: () => void;
  focusKey?: number;
  isMatched?: boolean;
}

function getSlotRole(index: number, activeIndex: number, total: number): 'center' | 'left' | 'right' | 'off-left' | 'off-right' {
  if (index === activeIndex) return 'center';
  if (total === 2) {
    return index > activeIndex ? 'right' : 'left';
  }
  let diff = index - activeIndex;
  if (diff > total / 2) diff -= total;
  if (diff < -total / 2) diff += total;
  if (diff === -1) return 'left';
  if (diff === 1) return 'right';
  return diff < 0 ? 'off-left' : 'off-right';
}

function rubberband(overshoot: number, dimension = 260, constant = 0.55): number {
  return (overshoot * dimension * constant) / (dimension + constant * Math.abs(overshoot));
}

/** The interactive three-slot 3D stage used when browsing and configuring a product. */
export function ShowcaseStage({
  previous,
  current,
  next,
  slides,
  activeIndex = 0,
  canNavigate,
  onNavigate,
  onSelectIndex,
  onExplore,
  focusKey = 0,
  isMatched = false,
}: ShowcaseStageProps) {
  const dragStart = useRef<{
    x: number;
    y: number;
    active: boolean;
    lockedAxis: 'x' | 'y' | null;
    history: Array<{ x: number; t: number }>;
  } | null>(null);
  const [dragX, setDragX] = useState(0);
  const [isFocusing, setIsFocusing] = useState(false);

  useEffect(() => {
    if (!focusKey) return;
    setIsFocusing(true);
    const timer = window.setTimeout(() => setIsFocusing(false), 560);
    return () => window.clearTimeout(timer);
  }, [focusKey]);

  const handlePointerDown = (e: React.PointerEvent<HTMLDivElement>) => {
    if ((e.target as HTMLElement).closest('button')) return;
    dragStart.current = {
      x: e.clientX,
      y: e.clientY,
      active: true,
      lockedAxis: null,
      history: [{ x: e.clientX, t: performance.now() }],
    };
  };

  const handlePointerMove = (e: React.PointerEvent<HTMLDivElement>) => {
    const state = dragStart.current;
    if (!state?.active) return;
    const dx = e.clientX - state.x;
    const dy = e.clientY - state.y;

    if (!state.lockedAxis) {
      if (Math.abs(dx) < 8 && Math.abs(dy) < 8) return;
      state.lockedAxis = Math.abs(dx) >= Math.abs(dy) ? 'x' : 'y';
      if (state.lockedAxis === 'x') {
        try {
          e.currentTarget.setPointerCapture(e.pointerId);
        } catch {
          // Ignore if pointer capture is unavailable
        }
      }
    }

    if (state.lockedAxis !== 'x') return;

    const now = performance.now();
    state.history.push({ x: e.clientX, t: now });
    if (state.history.length > 5) state.history.shift();

    if (!canNavigate) {
      // Soft rubber-band resistance on single-image products
      setDragX(rubberband(dx, 180, 0.35));
      return;
    }

    const clamped = Math.abs(dx) > 120 ? Math.sign(dx) * (120 + rubberband(Math.abs(dx) - 120, 140)) : dx;
    setDragX(clamped);
  };

  const handlePointerEnd = (e: React.PointerEvent<HTMLDivElement>) => {
    const state = dragStart.current;
    if (!state?.active) return;
    state.active = false;
    try {
      if (e.currentTarget.hasPointerCapture(e.pointerId)) {
        e.currentTarget.releasePointerCapture(e.pointerId);
      }
    } catch {
      // Ignore if pointer capture was not held
    }

    if (canNavigate && state.lockedAxis === 'x') {
      let velocity = 0;
      const history = state.history;
      if (history.length >= 2) {
        const first = history[0];
        const last = history[history.length - 1];
        const dt = (last.t - first.t) / 1000;
        if (dt > 0.005) {
          velocity = (last.x - first.x) / dt;
        }
      }

      if (Math.abs(dragX) > 40 || Math.abs(velocity) > 320) {
        const dir = dragX !== 0 ? (dragX < 0 ? 1 : -1) : velocity < 0 ? 1 : -1;
        onNavigate(dir);
      }
    }
    setDragX(0);
  };

  const hasSlides = Array.isArray(slides) && slides.length > 0;
  const safeActive = hasSlides ? Math.max(0, Math.min(activeIndex, slides.length - 1)) : 0;

  return (
    <div
      className={`showcase-stage-scene${dragX !== 0 ? ' is-dragging' : ''}${isMatched ? ' is-matched' : ''}${isFocusing ? ' is-focusing' : ''}`}
      role="region"
      aria-label="Interactive product stage"
      tabIndex={0}
      style={{ '--stage-drag-x': `${dragX}px` } as React.CSSProperties}
      onPointerDown={handlePointerDown}
      onPointerMove={handlePointerMove}
      onPointerUp={handlePointerEnd}
      onPointerCancel={handlePointerEnd}
      onKeyDown={(event) => {
        if (canNavigate && ['ArrowLeft', 'ArrowRight'].includes(event.key)) {
          event.preventDefault();
          event.stopPropagation();
          onNavigate(event.key === 'ArrowRight' ? 1 : -1);
        }
      }}
    >
      {canNavigate && (
        <>
          <button
            type="button"
            className="design-carousel-arrow prev"
            aria-label="Previous product image"
            onClick={() => onNavigate(-1)}
          >
            <ChevronLeft size={19} />
          </button>
          <button
            type="button"
            className="design-carousel-arrow next"
            aria-label="Next product image"
            onClick={() => onNavigate(1)}
          >
            <ChevronRight size={19} />
          </button>
        </>
      )}

      <div className="design-stage">
        {hasSlides ? (
          slides.map((slide, i) => {
            const role = getSlotRole(i, safeActive, slides.length);
            const isCenter = role === 'center';
            const isHidden = role === 'off-left' || role === 'off-right';

            return (
              <button
                key={slide.key}
                type="button"
                className={`design-bag-slot ${role}`}
                aria-current={isCenter ? 'true' : undefined}
                aria-label={
                  isCenter
                    ? slide.alt
                    : role === 'left'
                      ? 'Previous stage view'
                      : 'Next stage view'
                }
                aria-hidden={isHidden}
                tabIndex={isHidden || (isCenter && !onExplore) ? -1 : 0}
                disabled={(!canNavigate && !isCenter) || isHidden}
                onClick={() => {
                  if (isCenter) {
                    onExplore?.();
                    return;
                  }
                  if (onSelectIndex) onSelectIndex(i);
                  else onNavigate(role === 'left' ? -1 : 1);
                }}
              >
                {slide.url ? (
                  <StoreImage className="product-stage-image" src={slide.url} alt={slide.alt} draggable={false} />
                ) : isCenter ? (
                  <p className="product-image-empty">Product photography coming soon</p>
                ) : null}
                <div className="design-bag-shadow" />
              </button>
            );
          })
        ) : (
          <>
            <button
              type="button"
              className="design-bag-slot left"
              aria-label="Previous stage view"
              disabled={!canNavigate}
              onClick={() => onNavigate(-1)}
            >
              {previous?.url && <StoreImage src={previous.url} alt={previous.alt} draggable={false} />}
              <div className="design-bag-shadow" />
            </button>
            <div
              className="design-bag-slot center"
              onClick={onExplore}
              role={onExplore ? 'button' : undefined}
              tabIndex={onExplore ? 0 : undefined}
              onKeyDown={(event) => {
                if (onExplore && ['Enter', ' '].includes(event.key)) {
                  event.preventDefault();
                  onExplore();
                }
              }}
            >
              {current?.url ? (
                <StoreImage className="product-stage-image" src={current.url} alt={current.alt} draggable={false} />
              ) : (
                <p className="product-image-empty">Product photography coming soon</p>
              )}
              <div className="design-bag-shadow" />
            </div>
            <button
              type="button"
              className="design-bag-slot right"
              aria-label="Next stage view"
              disabled={!canNavigate}
              onClick={() => onNavigate(1)}
            >
              {next?.url && <StoreImage src={next.url} alt={next.alt} draggable={false} />}
              <div className="design-bag-shadow" />
            </button>
          </>
        )}
      </div>

      {hasSlides && slides.length > 1 && (
        <div className="stage-pagination" role="tablist" aria-label="Product stage slides">
          {slides.map((slide, i) => (
            <button
              key={slide.key}
              type="button"
              role="tab"
              aria-selected={i === safeActive}
              aria-label={slide.alt}
              className={`stage-dot${i === safeActive ? ' is-active' : ''}`}
              onClick={() => {
                if (onSelectIndex) onSelectIndex(i);
                else if (i !== safeActive) onNavigate(i > safeActive ? 1 : -1);
              }}
            />
          ))}
        </div>
      )}
    </div>
  );
}
