'use client';

import { SophieBagPlaceholder } from './SophieBagPlaceholder';

export function SophieSectionError({ onRetry }: { onRetry: () => void }) {
  return (
    <section className="py-12 sm:py-20" role="alert">
      <div className="max-w-[1140px] mx-auto px-4 sm:px-5">
        <div className="bg-[#f3f4f6] py-12 sm:py-16 px-5 sm:px-6 text-center">
          <SophieBagPlaceholder />
          <h2 className="font-['Oswald'] text-[24px] sm:text-[28px] font-semibold uppercase text-[#111] mt-6">Something went wrong</h2>
          <p className="font-['Poppins'] text-[12.5px] text-[#555] mt-2 max-w-md mx-auto leading-relaxed">
            We couldn&apos;t load the collection right now.
          </p>
          <button
            type="button"
            onClick={onRetry}
            className="inline-flex items-center justify-center min-h-[44px] mt-7 bg-black text-white font-['Oswald'] text-[11.5px] tracking-[0.8px] uppercase px-7 py-3 hover:bg-[#2c2c2c] transition-colors"
          >
            TRY AGAIN
          </button>
        </div>
      </div>
    </section>
  );
}