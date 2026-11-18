import { BrandLogo } from '@alfarid/ui/branding';

export function StorefrontLogo({ className }: { className: string }) {
  return (
    <BrandLogo className={className} fallback={
      <img src="/images/varya-header.webp" alt="" width={360} height={144} className={className + ' object-contain'} />
    } />
  );
}
