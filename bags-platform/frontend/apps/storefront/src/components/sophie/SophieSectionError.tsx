'use client';

import { SophieBagPlaceholder } from './SophieBagPlaceholder';

export function SophieSectionError({ onRetry }: { onRetry: () => void }) {
  return (
    <section className="py-20" role="alert">
      <div className="max-w-[1140px] mx-auto px-5">
        <div className="bg-[#f3f4f6] py-16 px-6 text-center">
          <SophieBagPlaceholder />
          <h2 className="font-['Oswald'] text-[28px] font-semibold uppercase text-[#111] mt-6">Something went wrong</h2>
          <p className="font-['Poppins'] text-[12px] text-[#555] mt-2">We couldn&apos;t load the collection right now.</p>
          <button onClick={onRetry} className="inline-block mt-7 bg-black text-white font-['Oswald'] text-[11px] tracking-[0.8px] uppercase px-7 py-2.5 hover:bg-[#2c2c2c]">TRY AGAIN</button>
        </div>
      </div>
    </section>
  );
}
