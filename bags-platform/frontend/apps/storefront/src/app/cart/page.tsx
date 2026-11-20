import { SophieFooter } from '@/components/sophie/layout/SophieFooter';
import { SophieCartContents } from '@/components/sophie/overlays/SophieCartDrawer';

export default function CartPage() {
  return (
    <>
      <main id="main-content" tabIndex={-1} className="mx-auto w-full max-w-3xl px-4 py-8 sm:px-6 sm:py-12">
        <div className="overflow-hidden rounded-lg border border-gray-200 bg-white">
          <SophieCartContents />
        </div>
      </main>
      <SophieFooter />
    </>
  );
}
