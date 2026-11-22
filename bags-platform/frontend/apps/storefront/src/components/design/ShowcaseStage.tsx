'use client';

import { useRef, useState } from 'react';
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
}: ShowcaseStageProps) {
  const dragStart = useRef<{ x: number; y: number; active: boolean } | null>(null);
  const [dragX, setDragX] = useState(0);

  const handlePointerDown = (e: React.PointerEvent<HTMLDivElement>) => {
    if (!canNavigate) return;
    if ((e.target as HTMLElement).closest('button')) return;
    dragStart.current = { x: e.clientX, y: e.clientY, active: true };
  };

  const handlePointerMove = (e: React.PointerEvent<HTMLDivElement>) => {
    if (!dragStart.current?.active) return;
    const dx = e.clientX - dragStart.current.x;
    const dy = e.clientY - dragStart.current.y;
    if (Math.abs(dx) > Math.abs(dy) && Math.abs(dx) > 6) {
      setDragX(Math.max(-140, Math.min(140, dx)));
    }
  };

  const handlePointerEnd = () => {
    if (!dragStart.current?.active) return;
    dragStart.current.active = false;
    if (Math.abs(dragX) > 45) {
      onNavigate(dragX < 0 ? 1 : -1);
    }
    setDragX(0);
  };

  const hasSlides = Array.isArray(slides) && slides.length > 0;
  const safeActive = hasSlides ? Math.max(0, Math.min(activeIndex, slides.length - 1)) : 0;

  return (
    <div
      className={`showcase-stage-scene${dragX !== 0 ? ' is-dragging' : ''}`}
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
