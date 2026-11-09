'use client';

import { useBranding } from '@alfarid/ui/branding';

export function SophieBagPlaceholder() {
  const { branding } = useBranding();
  return (
    <div className="flex flex-col items-center justify-center" aria-hidden="true">
      <svg className="w-[90px] text-[#d4d8dd]" viewBox="0 0 100 100" fill="none">
        <path d="M20 35h60l6 52H14l6-52Z" fill="currentColor" />
        <path d="M35 39V27a15 15 0 0 1 30 0v12" stroke="currentColor" strokeWidth="6" />
      </svg>
      <span dir="auto" className="font-['Oswald'] text-[10px] tracking-[3px] text-[#b9bec4] mt-2 max-w-[150px] truncate uppercase">{branding.name}</span>
    </div>
  );
}