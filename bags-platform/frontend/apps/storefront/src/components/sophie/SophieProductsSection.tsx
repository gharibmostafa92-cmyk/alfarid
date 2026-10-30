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
  className = 'py-12 sm:py-16 pb-10',
  isLoading = false,
}: SophieProductsSectionProps) {
  if (!isLoading && products.length === 0) return null;
  return (
    <section id={id} className={`products-section ${className}`} aria-busy={isLoading} aria-label={title}>
      <div className="max-w-[1140px] mx-auto px-4 sm:px-5">
        {/* Section Header */}
        {isLoading ? (
          <div className="text-center mb-8 sm:mb-10 space-y-3" aria-hidden="true">
            <div className="sophie-skeleton h-7 w-56 max-w-full mx-auto" />
            <div className="sophie-skeleton h-3 w-72 max-w-full mx-auto" />
          </div>
        ) : (
          <div className="text-center mb-8 sm:mb-10">
            <h2 className="font-['Oswald'] text-[24px] sm:text-[28px] font-semibold uppercase tracking-[0.5px] text-[#111] mb-1.5">
              {title}
            </h2>
            <p className="text-[12.5px] text-[#555] font-normal font-['Poppins']">
              {subtitle}
            </p>
          </div>
        )}

        {/* Responsive Product Grid: 2 cols on phones/tablets, 4 cols on desktop */}
        <div
          className={
            !isLoading && products.length < 4
              ? 'flex flex-wrap justify-center gap-3.5 sm:gap-6 lg:gap-7'
              : 'grid grid-cols-2 lg:grid-cols-4 gap-3.5 sm:gap-6 lg:gap-7'
          }
        >
          {isLoading
            ? Array.from({ length: 4 }, (_, index) => (
                <div key={index} aria-hidden="true">
                  <div className="sophie-skeleton h-[210px] sm:h-[260px] mb-3" />
                  <div className="sophie-skeleton h-3 w-3/4 mx-auto mb-2.5" />
                  <div className="sophie-skeleton h-3 w-1/3 mx-auto" />
                </div>
              ))
            : products.map((product) => (
                <div
                  key={product.id}
                  className={
                    products.length < 4
                      ? 'w-[calc(50%-7px)] sm:w-[calc(50%-12px)] lg:w-[calc(25%-21px)] min-w-0'
                      : 'min-w-0'
                  }
                >
                  <SophieProductCard product={product} />
                </div>
              ))}
        </div>

        {/* View More Button */}
        {!isLoading && showViewMore && products.length >= 4 && (
          <div className="text-center mt-8 sm:mt-9">
            <a
              href={viewMoreHref}
              className="inline-flex items-center justify-center min-h-[44px] bg-black text-white font-['Oswald'] text-[11.5px] font-medium tracking-[0.8px] uppercase px-8 py-3 hover:bg-[#2c2c2c] transition-colors"
            >
              VIEW MORE
            </a>
          </div>
        )}
      </div>
    </section>
  );
}