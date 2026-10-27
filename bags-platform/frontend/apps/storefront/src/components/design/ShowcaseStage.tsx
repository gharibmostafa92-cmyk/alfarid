'use client';

import { ChevronLeft, ChevronRight } from 'lucide-react';
import { StoreImage } from '../StoreImage';

interface StageImage { url: string | null; alt: string }
interface ShowcaseStageProps {
  previous: StageImage;
  current: StageImage;
  next: StageImage;
  canNavigate: boolean;
  onNavigate: (direction: number) => void;
  onExplore?: () => void;
}

/** The same three-slot stage is used when browsing and configuring a product. */
export function ShowcaseStage({ previous, current, next, canNavigate, onNavigate, onExplore }: ShowcaseStageProps) {
  return <div className="showcase-stage-scene" role="region" aria-label="Interactive product stage" tabIndex={0}
    onKeyDown={(event) => { if (canNavigate && ['ArrowLeft', 'ArrowRight'].includes(event.key)) { event.preventDefault(); event.stopPropagation(); onNavigate(event.key === 'ArrowRight' ? 1 : -1); } }}>
    {canNavigate && <><button type="button" className="design-carousel-arrow prev" aria-label="Previous product image" onClick={() => onNavigate(-1)}><ChevronLeft size={19} /></button><button type="button" className="design-carousel-arrow next" aria-label="Next product image" onClick={() => onNavigate(1)}><ChevronRight size={19} /></button></>}
    <div className="design-stage">
      <button type="button" className="design-bag-slot left" aria-label="Previous stage view" disabled={!canNavigate} onClick={() => onNavigate(-1)}>
        {previous.url && <StoreImage src={previous.url} alt={previous.alt} draggable={false} />}<div className="design-bag-shadow" />
      </button>
      <div className="design-bag-slot center" onClick={onExplore} role={onExplore ? 'button' : undefined} tabIndex={onExplore ? 0 : undefined} onKeyDown={(event) => { if (onExplore && ['Enter', ' '].includes(event.key)) { event.preventDefault(); onExplore(); } }}>
        {current.url ? <StoreImage className="product-stage-image" src={current.url} alt={current.alt} draggable={false} /> : <p className="product-image-empty">Product photography coming soon</p>}<div className="design-bag-shadow" />
      </div>
      <button type="button" className="design-bag-slot right" aria-label="Next stage view" disabled={!canNavigate} onClick={() => onNavigate(1)}>
        {next.url && <StoreImage src={next.url} alt={next.alt} draggable={false} />}<div className="design-bag-shadow" />
      </button>
    </div>
  </div>;
}
