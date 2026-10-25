'use client';

import React from 'react';
import { SophieProduct, SophieProductCard } from './SophieProductCard';

interface SophieProductsSectionProps {
  id?: string;
  title: string;
  subtitle: string;
  products: SophieProduct[];
  showViewMore?: boolean;
  viewMoreHref?: string;
  className?: string;
  isLoading?: boolean;
}

export function SophieProductsSection({
  id,
  title,
  subtitle,
  products,
  showViewMore = false,
  viewMoreHref = '#',
  className = 'py-16 pb-10',
  isLoading = false,
}: SophieProductsSectionProps) {
  if (!isLoading && products.length === 0) return null;
  return (
    <section id={id} className={`products-section ${className}`} aria-busy={isLoading} aria-label={title}>
      <div className="max-w-[1140px] mx-auto px-5">
        {/* Section Header */}
        {isLoading ? <div className="text-center mb-10 space-y-3" aria-hidden="true"><div className="sophie-skeleton h-7 w-56 mx-auto" /><div className="sophie-skeleton h-3 w-72 max-w-full mx-auto" /></div> : <div className="text-center mb-10">
          <h2 className="font-['Oswald'] text-[28px] font-semibold uppercase tracking-[0.5px] text-[#111] mb-1.5">
            {title}
          </h2>
          <p className="text-[12px] text-[#555] font-normal font-['Poppins']">
            {subtitle}
          </p>
        </div>}

        {/* 4-Column Product Grid */}
        <div className={!isLoading && products.length < 4 ? 'flex flex-wrap justify-center gap-7' : 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-7'}>
          {isLoading ? Array.from({ length: 4 }, (_, index) => <div key={index} aria-hidden="true"><div className="sophie-skeleton h-[260px] mb-3.5" /><div className="sophie-skeleton h-3 w-3/4 mx-auto mb-3" /><div className="sophie-skeleton h-3 w-1/4 mx-auto" /></div>) : products.map((product) => (
            <div key={product.id} className={products.length < 4 ? 'w-full sm:w-[calc(50%-14px)] lg:w-[calc(25%-21px)]' : 'min-w-0'}><SophieProductCard product={product} /></div>
          ))}
        </div>

        {/* View More Button */}
        {!isLoading && showViewMore && products.length >= 4 && (
          <div className="text-center mt-9">
            <a
              href={viewMoreHref}
              className="inline-block bg-black text-white font-['Oswald'] text-[11px] font-medium tracking-[0.8px] uppercase px-7 py-2.5 hover:bg-[#2c2c2c] transition-colors"
            >
              VIEW MORE
            </a>
          </div>
        )}
      </div>
    </section>
  );
}
