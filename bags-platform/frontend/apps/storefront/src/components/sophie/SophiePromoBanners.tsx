'use client';

import { useState } from 'react';
import type { StoreBanner } from '@alfarid/api-client';
import { StoreImage } from '../StoreImage';

const PALETTE = ['#dfe6ec', '#ede3de', '#e7e9e1', '#efe6dc'];

function PromoCard({ banner, index, wide, single }: { banner: StoreBanner; index: number; wide: boolean; single: boolean }) {
  const [failed, setFailed] = useState(false);
  const hasText = Boolean(banner.kicker || banner.headline || banner.headline_bold || banner.description);
  const external = banner.is_external ? { target: '_blank', rel: 'noopener noreferrer' } : {};
  return (
    <div className={`min-h-[235px] ${single ? 'md:aspect-[16/5]' : ''} ${wide ? 'md:col-span-2' : ''} flex items-stretch overflow-hidden relative group`} style={{ backgroundColor: banner.bg_color || PALETTE[index % PALETTE.length] }}>
      {failed ? <p dir="auto" className="w-full px-6 text-center font-['Poppins'] font-bold text-black">{banner.title}</p> : <>
        <div className={hasText ? 'w-[48%] shrink-0 relative' : 'absolute inset-0'}>
          <StoreImage src={banner.image} alt={banner.title} className="absolute inset-0 w-full h-full object-cover" loading="lazy" onError={() => setFailed(true)} />
        </div>
        {hasText && <div className="flex-1 min-w-0 px-4 sm:px-5 py-5 self-center break-words">
          {banner.kicker && <p dir="auto" className="font-['Oswald'] text-[13px] uppercase text-[#222] tracking-[0.5px] mb-0.5">{banner.kicker}</p>}
          {(banner.headline_bold || banner.headline) && <h3 dir="auto" className="font-['Poppins'] text-[24px] sm:text-[27px] text-black leading-[1.15] mb-2.5">{banner.headline}{banner.headline && banner.headline_bold ? ' ' : ''}<strong>{banner.headline_bold}</strong></h3>}
          {banner.description && <p dir="auto" className="text-[12px] leading-relaxed text-[#222] mb-3">{banner.description}</p>}
          {banner.href && <a href={banner.href} {...external} className="text-[12px] text-[#222] inline-flex items-center gap-1.5 hover:text-[#4f7d88] transition-colors">
            <span>{banner.cta_label || 'View collection'}</span>
            <svg className="w-2.5 h-2.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" aria-hidden="true"><path strokeLinecap="round" strokeLinejoin="round" strokeWidth={2.5} d="M9 5l7 7-7 7" /></svg>
          </a>}
        </div>}
      </>}
      {(!hasText || failed) && banner.href && <a href={banner.href} {...external} aria-label={banner.title} className="absolute inset-0" />}
    </div>
  );
}

export function SophiePromoBanners({ banners }: { banners: StoreBanner[] }) {
  if (!banners.length) return null;
  return <section className="py-6 pb-2.5" id="promo-banners">
    <div className="max-w-[1140px] mx-auto px-5">
      <div className="grid grid-cols-1 md:grid-cols-2 gap-7">
        {banners.map((banner, index) => <PromoCard key={`${banner.id}:${banner.image}`} banner={banner} index={index} single={banners.length === 1} wide={banners.length % 2 === 1 && index === banners.length - 1} />)}
      </div>
    </div>
  </section>;
}
